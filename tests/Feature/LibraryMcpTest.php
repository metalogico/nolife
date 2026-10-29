<?php

use App\Jobs\DownloadGameImage;
use App\Models\Game;
use App\Models\GameImage;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    config([
        'app.url' => 'http://localhost',
        'nolife.mcp_enabled' => true,
        'nolife.mcp_read_token_hash' => hash('sha256', 'read-fixture-token'),
        'nolife.mcp_write_token_hash' => hash('sha256', 'write-fixture-token'),
    ]);
});

function libraryMcpRpc(string $method, array $params = [], ?string $token = 'read-fixture-token'): TestResponse
{
    $headers = $token === null ? [] : ['Authorization' => 'Bearer '.$token];

    return test()->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params,
    ], $headers);
}

function libraryMcpTool(string $name, array $arguments = [], string $token = 'read-fixture-token'): TestResponse
{
    return libraryMcpRpc('tools/call', ['name' => $name, 'arguments' => (object) $arguments], $token);
}

it('returns 404 for MCP when it is disabled', function (): void {
    config(['nolife.mcp_enabled' => false]);

    libraryMcpRpc('tools/list')->assertNotFound();
    $this->get('/mcp')->assertNotFound();
});

it('returns 401 without a valid dedicated token', function (?string $token): void {
    libraryMcpRpc('tools/list', [], $token)->assertUnauthorized()->assertHeader('WWW-Authenticate');
})->with([null, 'wrong-token', '']);

it('does not accept empty or malformed configured hashes', function (): void {
    config(['nolife.mcp_read_token_hash' => '', 'nolife.mcp_write_token_hash' => 'invalid']);

    libraryMcpRpc('tools/list')->assertUnauthorized();
});

it('initializes a standard HTTP MCP client without a browser session', function (): void {
    libraryMcpRpc('initialize', [
        'protocolVersion' => '2025-06-18', 'capabilities' => (object) [],
        'clientInfo' => ['name' => 'NoLife test agent', 'version' => '1.0'],
    ])->assertOk()->assertJsonPath('result.serverInfo.name', 'NoLife Library')
        ->assertJsonPath('result.protocolVersion', '2025-06-18')
        ->assertHeader('Cache-Control', 'no-store, private');
});

it('advertises only permitted tools and does not retain a previous write permission', function (): void {
    $write = libraryMcpRpc('tools/list', [], 'write-fixture-token')->assertOk();
    expect(array_column($write->json('result.tools'), 'name'))->toBe([
        'search_games', 'get_game', 'get_library_summary', 'add_game', 'update_game',
    ]);

    $read = libraryMcpRpc('tools/list')->assertOk();
    expect(array_column($read->json('result.tools'), 'name'))->toBe([
        'search_games', 'get_game', 'get_library_summary',
    ]);
    libraryMcpRpc('tools/list', [], 'wrong-token')->assertUnauthorized();
});

it('refuses direct write-tool calls with a read token', function (string $tool): void {
    $game = Game::factory()->create(['title' => 'Unchanged']);

    libraryMcpTool($tool, ['id' => $game->id, 'title' => 'Changed', 'platform' => 'PC', 'format' => 'digital'])
        ->assertStatus(400)->assertJsonStructure(['error' => ['code', 'message']]);
    $this->assertDatabaseHas('games', ['id' => $game->id, 'title' => 'Unchanged']);
    $this->assertDatabaseCount('games', 1);
})->with(['add_game', 'update_game']);

it('rejects browser origins other than the configured application origin', function (string $origin): void {
    $this->withHeaders(['Origin' => $origin]);

    libraryMcpRpc('tools/list')->assertForbidden();
})->with(['https://untrusted.example', 'null', 'not-an-origin', 'http://localhost.evil.example']);

it('accepts the configured browser origin and default port', function (): void {
    $this->withHeaders(['Origin' => 'http://localhost:80']);

    libraryMcpRpc('tools/list')->assertOk();
});

it('searches all title matches across pages without changing the collection', function (): void {
    Http::preventStrayRequests();
    $games = Game::factory()->count(4)->sequence(
        ['title' => 'Assassin’s Creed Alpha'],
        ['title' => "ASSASSIN'S CREED Beta"],
        ['title' => 'Assassinʼs Creed Gamma'],
        ['title' => 'Another game'],
    )->create();
    $before = Game::query()->get()->toArray();

    $first = libraryMcpTool('search_games', ['query' => "assassin's creed", 'per_page' => 2])
        ->assertOk()->assertJsonPath('result.isError', false)
        ->assertJsonPath('result.structuredContent.meta.total', 3)
        ->assertJsonPath('result.structuredContent.meta.next_page', 2)->assertJsonCount(2, 'result.structuredContent.games');
    $second = libraryMcpTool('search_games', ['query' => 'assassin’s creed', 'per_page' => 2, 'page' => 2])
        ->assertOk()->assertJsonCount(1, 'result.structuredContent.games')
        ->assertJsonPath('result.structuredContent.meta.next_page', null);
    $ids = [...array_column($first->json('result.structuredContent.games'), 'id'), ...array_column($second->json('result.structuredContent.games'), 'id')];
    expect($ids)->toEqualCanonicalizing($games->take(3)->pluck('id')->all());
    expect(Game::query()->get()->toArray())->toBe($before);
    Http::assertNothingSent();
});

it('matches Unicode case and treats SQL wildcard characters literally', function (string $query, string $title): void {
    $game = Game::factory()->create(['title' => $title]);
    Game::factory()->create(['title' => 'Unrelated']);

    libraryMcpTool('search_games', ['query' => $query])->assertOk()
        ->assertJsonPath('result.structuredContent.meta.total', 1)
        ->assertJsonPath('result.structuredContent.games.0.id', $game->id);
})->with([
    ['ÉCLAIR', 'éclair Adventure'], ['100%', '100% Complete'], ['_', 'A_B'], ['\\', 'A\\B'],
]);

it('combines filters and preserves independent copies', function (): void {
    $owned = Game::factory()->playing()->create(['title' => 'Example', 'platform' => 'PC', 'format' => 'digital', 'genres' => ['Adventure']]);
    Game::factory()->playing()->create(['title' => 'Example', 'platform' => 'PS5', 'genres' => ['Adventure']]);
    Game::factory()->create(['title' => 'Example', 'platform' => 'PS4', 'genres' => ['Adventure RPG']]);

    libraryMcpTool('search_games', ['query' => 'example'])->assertOk()->assertJsonCount(3, 'result.structuredContent.games');
    libraryMcpTool('search_games', ['query' => 'example', 'platform' => 'PC', 'status' => 'playing', 'format' => 'digital', 'genre' => 'Adventure'])
        ->assertOk()->assertJsonPath('result.structuredContent.meta.total', 1)
        ->assertJsonPath('result.structuredContent.games.0.id', $owned->id);
});

it('ranks personal scores and reports unrated copies', function (): void {
    Game::factory()->create(['title' => 'Lower', 'rating' => 5]);
    $first = Game::factory()->create(['title' => 'Alpha', 'rating' => 9]);
    Game::factory()->create(['title' => 'Beta', 'rating' => 9]);
    Game::factory()->create(['title' => 'Unrated']);

    libraryMcpTool('search_games', ['sort_by' => 'rating', 'per_page' => 2])->assertOk()
        ->assertJsonPath('result.structuredContent.games.0.id', $first->id)
        ->assertJsonPath('result.structuredContent.meta.total', 3)
        ->assertJsonPath('result.structuredContent.meta.matching_total', 4)
        ->assertJsonPath('result.structuredContent.meta.missing_sort_values', 1);
});

it('distinguishes expected length from time played and retains known zeroes', function (string $sort, string $firstTitle): void {
    Game::factory()->create(['title' => 'Expected longest', 'duration_minutes' => 600, 'total_played_minutes' => 60]);
    Game::factory()->create(['title' => 'Played longest', 'duration_minutes' => 120, 'total_played_minutes' => 1200]);
    Game::factory()->create(['title' => 'Zero', 'duration_minutes' => 0, 'total_played_minutes' => 0]);
    Game::factory()->create(['title' => 'Unknown']);

    libraryMcpTool('search_games', ['sort_by' => $sort])->assertOk()
        ->assertJsonPath('result.structuredContent.games.0.title', $firstTitle)
        ->assertJsonPath('result.structuredContent.games.2.'.$sort, 0)
        ->assertJsonPath('result.structuredContent.meta.total', 3)
        ->assertJsonPath('result.structuredContent.meta.missing_sort_values', 1);
})->with([['duration_minutes', 'Expected longest'], ['total_played_minutes', 'Played longest']]);

it('sorts years and insertion dates in the requested direction', function (string $sort): void {
    $first = Game::factory()->create(['title' => 'Earlier', 'release_year' => 2000, 'created_at' => '2020-01-01 00:00:00']);
    Game::factory()->create(['title' => 'Later', 'release_year' => 2020, 'created_at' => '2021-01-01 00:00:00']);

    libraryMcpTool('search_games', ['sort_by' => $sort, 'sort_direction' => 'asc'])->assertOk()
        ->assertJsonPath('result.structuredContent.games.0.id', $first->id);
})->with(['release_year', 'created_at']);

it('returns a tool validation error for unsafe or unsupported search arguments', function (array $arguments): void {
    libraryMcpTool('search_games', $arguments)->assertOk()->assertJsonPath('result.isError', true);
})->with([
    [['sort_by' => 'title; DROP TABLE games']], [['sort_direction' => 'desc; --']],
    [['per_page' => 101]], [['page' => 0]], [['query' => ['invalid']]], [['sql' => 'select * from games']],
]);

it('reads detail and summary without writes or provider requests', function (): void {
    Http::preventStrayRequests();
    $game = Game::factory()->playing()->create(['genres' => ['Adventure'], 'duration_minutes' => 0]);
    $before = $game->fresh()->toArray();

    libraryMcpTool('get_game', ['id' => $game->id])->assertOk()
        ->assertJsonPath('result.structuredContent.game.title', $game->title)
        ->assertJsonPath('result.structuredContent.game.duration_minutes', 0);
    libraryMcpTool('get_library_summary')->assertOk()
        ->assertJsonPath('result.structuredContent.total', 1)
        ->assertJsonPath('result.structuredContent.counts.playing', 1)
        ->assertJsonPath('result.structuredContent.platforms', ['PS5'])
        ->assertJsonPath('result.structuredContent.genres', ['Adventure']);
    expect($game->fresh()->toArray())->toBe($before);
    Http::assertNothingSent();
});

it('returns a safe error for an absent game', function (): void {
    libraryMcpTool('get_game', ['id' => 999999])->assertOk()
        ->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.content.0.text', 'The requested game was not found.');
});

it('adds a planned manual copy with shared validation and no unexpected jobs', function (): void {
    Http::preventStrayRequests();
    Queue::fake([DownloadGameImage::class]);

    libraryMcpTool('add_game', ['title' => '  New game  ', 'platform' => ' PC ', 'format' => 'digital'], 'write-fixture-token')
        ->assertOk()->assertJsonPath('result.isError', false)
        ->assertJsonPath('result.structuredContent.game.title', 'New game')
        ->assertJsonPath('result.structuredContent.game.status', 'planned');
    $this->assertDatabaseHas('games', ['title' => 'New game', 'platform' => 'PC', 'rating' => null]);
    Http::assertNothingSent();
    Queue::assertNothingPushed();
});

it('rejects invalid game data without creating records', function (array $arguments): void {
    libraryMcpTool('add_game', $arguments, 'write-fixture-token')->assertOk()->assertJsonPath('result.isError', true);
    $this->assertDatabaseCount('games', 0);
})->with([
    [[]],
    [['title' => 'Game', 'platform' => 'PC', 'format' => 'digital', 'rating' => 11]],
    [['title' => 'Game', 'platform' => 'PC', 'format' => 'digital', 'duration_minutes' => -1]],
    [['title' => 'Game', 'platform' => 'PC', 'format' => 'digital', 'created_at' => '2000-01-01']],
]);

it('returns the existing duplicate error without adding or changing a copy', function (): void {
    $game = Game::factory()->create(['title' => 'Duplicate', 'rating' => 8]);

    $response = libraryMcpTool('add_game', ['title' => 'duplicate', 'platform' => 'PS5', 'format' => 'physical'], 'write-fixture-token')
        ->assertOk()->assertJsonPath('result.isError', true);
    expect($response->json('result.content.0.text'))->toContain('already in your collection');
    $this->assertDatabaseCount('games', 1);
    $this->assertDatabaseHas('games', ['id' => $game->id, 'rating' => 8]);
});

it('updates only the selected copy and preserves omitted values and images', function (): void {
    $game = Game::factory()->create(['title' => 'Selected', 'rating' => 7, 'duration_minutes' => 600, 'total_played_minutes' => 120]);
    $header = GameImage::factory()->for($game)->create(['kind' => 'screenshot']);
    $game->update(['header_image_id' => $header->id]);
    $other = Game::factory()->create(['title' => 'Other', 'rating' => 5]);

    libraryMcpTool('update_game', ['id' => $game->id, 'status' => 'completed', 'rating' => 9], 'write-fixture-token')
        ->assertOk()->assertJsonPath('result.structuredContent.game.status', 'completed')
        ->assertJsonPath('result.structuredContent.game.duration_minutes', 600)
        ->assertJsonPath('result.structuredContent.game.header_image_id', $header->id);
    $this->assertDatabaseHas('games', ['id' => $game->id, 'rating' => 9, 'total_played_minutes' => 120]);
    $this->assertDatabaseHas('games', ['id' => $other->id, 'rating' => 5, 'status' => 'planned']);
    $this->assertModelExists($header);
});

it('clears nullable fields explicitly and keeps zero play time', function (): void {
    $game = Game::factory()->create(['rating' => 8, 'duration_minutes' => 60, 'total_played_minutes' => 120]);

    libraryMcpTool('update_game', ['id' => $game->id, 'rating' => null, 'duration_minutes' => null, 'total_played_minutes' => 0], 'write-fixture-token')
        ->assertOk()->assertJsonPath('result.isError', false);
    $this->assertDatabaseHas('games', ['id' => $game->id, 'rating' => null, 'duration_minutes' => null, 'total_played_minutes' => 0]);
});

it('rejects empty updates and unsupported identity changes', function (array $changes): void {
    $game = Game::factory()->create(['rating' => 8]);

    libraryMcpTool('update_game', ['id' => $game->id, ...$changes], 'write-fixture-token')
        ->assertOk()->assertJsonPath('result.isError', true);
    $this->assertDatabaseHas('games', ['id' => $game->id, 'rating' => 8, 'igdb_id' => null]);
})->with([[[]], [['igdb_id' => 1942]], [['rating' => 0]], [['total_played_minutes' => -1]], [['title' => '   ']], [['platform' => '']]]);

it('adds a known IGDB game using the existing metadata and image job flow', function (): void {
    config(['nolife.igdb_client_id' => 'fixture-client', 'nolife.igdb_client_secret' => 'fixture-secret']);
    Http::preventStrayRequests();
    Queue::fake([DownloadGameImage::class]);
    Http::fake([
        'https://id.twitch.tv/oauth2/token' => Http::response(['access_token' => 'fixture-token', 'expires_in' => 3600]),
        'https://api.igdb.com/v4/games' => Http::response([igdbFixture()]),
    ]);

    libraryMcpTool('add_game', ['title' => 'Known game', 'platform' => 'PC', 'format' => 'digital', 'igdb_id' => 1942], 'write-fixture-token')
        ->assertOk()->assertJsonPath('result.structuredContent.game.title', 'God of War');
    $this->assertDatabaseHas('games', ['igdb_id' => 1942, 'platform' => 'PC', 'format' => 'digital']);
    Queue::assertPushed(DownloadGameImage::class, 3);
    Http::assertSentCount(2);
});

it('rejects a header belonging to a different copy without saving other changes', function (): void {
    $game = Game::factory()->create(['rating' => 8]);
    $otherImage = GameImage::factory()->create(['kind' => 'screenshot']);

    libraryMcpTool('update_game', ['id' => $game->id, 'rating' => 9, 'header_image_id' => $otherImage->id], 'write-fixture-token')
        ->assertOk()->assertJsonPath('result.isError', true);
    $this->assertDatabaseHas('games', ['id' => $game->id, 'rating' => 8, 'header_image_id' => null]);
});

it('reports unavailable IGDB without creating a partial copy', function (): void {
    config(['nolife.igdb_client_id' => '', 'nolife.igdb_client_secret' => '']);
    Http::preventStrayRequests();

    $response = libraryMcpTool('add_game', ['title' => 'Known game', 'platform' => 'PC', 'format' => 'digital', 'igdb_id' => 1942], 'write-fixture-token')
        ->assertOk()->assertJsonPath('result.isError', true);
    expect($response->json('result.content.0.text'))->toContain('IGDB is not configured');
    $this->assertDatabaseCount('games', 0);
    Http::assertNothingSent();
});

it('limits each credential independently and allows requests after the cooldown', function (): void {
    for ($attempt = 0; $attempt < 120; $attempt++) {
        libraryMcpRpc('tools/list')->assertOk();
    }
    libraryMcpRpc('tools/list')->assertTooManyRequests()->assertHeader('Retry-After');
    libraryMcpRpc('tools/list', [], 'write-fixture-token')->assertOk();

    $this->travel(61)->seconds();
    libraryMcpRpc('tools/list')->assertOk();
});

it('normalizes blank optional strings like the browser API', function (): void {
    $game = Game::factory()->create(['summary' => 'Old summary', 'rating' => 8]);

    libraryMcpTool('update_game', ['id' => $game->id, 'summary' => '   ', 'rating' => ''], 'write-fixture-token')
        ->assertOk()->assertJsonPath('result.isError', false);
    $this->assertDatabaseHas('games', ['id' => $game->id, 'summary' => null, 'rating' => null]);
});
