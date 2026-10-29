<?php

use App\Jobs\DownloadGameImage;
use App\Models\Game;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    config([
        'nolife.username' => 'michele', 'nolife.password_hash' => Hash::make('test-password-123'),
        'nolife.igdb_client_id' => 'fixture-client', 'nolife.igdb_client_secret' => 'fixture-secret',
    ]);
    Http::preventStrayRequests();
});

it('searches IGDB through the backend without changing the library', function (): void {
    Http::fake([
        'https://id.twitch.tv/oauth2/token' => Http::response(['access_token' => 'fixture-token', 'expires_in' => 3600]),
        'https://api.igdb.com/v4/games' => Http::response([igdbFixture()]),
    ]);
    $this->withSession(personalSession())->getJson('/api/v1/igdb/search?query=God%20of%20War')
        ->assertOk()->assertJsonPath('data.0.id', 1942)->assertJsonPath('data.0.title', 'God of War');
    $this->assertDatabaseCount('games', 0);
    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.igdb.com/v4/games'
        && $request->hasHeader('Authorization', 'Bearer fixture-token')
        && str_contains($request->body(), 'search "God of War";'));
});

it('looks up a game by an exact numeric ID', function (): void {
    Http::fake([
        'https://id.twitch.tv/oauth2/token' => Http::response(['access_token' => 'fixture-token', 'expires_in' => 3600]),
        'https://api.igdb.com/v4/games' => Http::response([igdbFixture()]),
    ]);
    $this->withSession(personalSession())->getJson('/api/v1/igdb/search?query=1942&by=id')
        ->assertOk()->assertJsonPath('data.0.id', 1942);
    Http::assertSent(fn (Request $request): bool => str_contains($request->body(), 'where id = 1942;'));
});

it('orders search matches by newest release date with undated games last', function (): void {
    Http::fake([
        'https://id.twitch.tv/oauth2/token' => Http::response(['access_token' => 'fixture-token', 'expires_in' => 3600]),
        'https://api.igdb.com/v4/games' => Http::response([
            ['id' => 1, 'name' => 'Older', 'first_release_date' => 1000000000],
            ['id' => 2, 'name' => 'Undated'],
            ['id' => 5, 'name' => 'Before the Unix epoch', 'first_release_date' => -10000],
            ['id' => 3, 'name' => 'Newest', 'first_release_date' => 1700000000],
            ['id' => 4, 'name' => 'Earlier in the same year', 'first_release_date' => 1680000000],
        ]),
    ]);

    $response = $this->withSession(personalSession())->getJson('/api/v1/igdb/search?query=Release%20order')->assertOk();

    expect(array_column($response->json('data'), 'id'))->toBe([3, 4, 1, 5, 2]);
    $this->assertDatabaseCount('games', 0);
    Http::assertSentCount(2);
});

it('uses landscape artwork and excludes provider logos from the game imagery', function (): void {
    $fixture = igdbFixture();
    $fixture['artworks'] = [
        ['image_id' => 'logo', 'image_type' => ['name' => 'Logo'], 'width' => 1600, 'height' => 600],
        ['image_id' => 'portrait', 'width' => 600, 'height' => 900],
        ['image_id' => 'character', 'alpha_channel' => true],
        ['image_id' => 'landscape', 'image_type' => ['name' => 'Key Art'], 'width' => 1920, 'height' => 1080],
    ];
    Http::fake([
        'https://id.twitch.tv/oauth2/token' => Http::response(['access_token' => 'fixture-token', 'expires_in' => 3600]),
        'https://api.igdb.com/v4/games' => Http::response([$fixture]),
    ]);
    $response = $this->withSession(personalSession())->getJson('/api/v1/igdb/games/1942')->assertOk();
    $artworks = collect($response->json('data.images'))->where('kind', 'artwork')->pluck('source_id')->all();
    expect($artworks)->toBe(['landscape']);
});

it('applies metadata only to the selected copy while preserving personal values', function (): void {
    $game = Game::factory()->playing()->create(['title' => 'My game', 'rating' => 9, 'duration_minutes' => 1530, 'total_played_minutes' => 750, 'format' => 'digital', 'platform' => 'PS5']);
    $other = Game::factory()->create(['title' => 'My other copy', 'platform' => 'PS4']);
    Queue::fake([DownloadGameImage::class]);
    Http::fake([
        'https://id.twitch.tv/oauth2/token' => Http::response(['access_token' => 'fixture-token', 'expires_in' => 3600]),
        'https://api.igdb.com/v4/games' => Http::response([igdbFixture()]),
    ]);
    $this->withSession(personalSession())->postJson('/api/v1/games/'.$game->id.'/metadata', ['igdb_id' => 1942])
        ->assertOk()->assertJsonPath('data.title', 'God of War')
        ->assertJsonPath('data.rating', 9)->assertJsonPath('data.status', 'playing')
        ->assertJsonPath('data.duration_minutes', 1530)->assertJsonPath('data.total_played_minutes', 750)->assertJsonPath('data.format', 'digital')
        ->assertJsonPath('data.platform', 'PS5')->assertJsonCount(3, 'data.images');
    $this->assertDatabaseHas('games', ['id' => $other->id, 'title' => 'My other copy', 'igdb_id' => null]);
    $this->assertDatabaseHas('games', ['id' => $game->id, 'duration_minutes' => 1530, 'total_played_minutes' => 750]);
    Queue::assertPushed(DownloadGameImage::class, 3);
    Http::assertSentCount(2);
});

it('creates separate platform copies from the same IGDB game', function (): void {
    Queue::fake([DownloadGameImage::class]);
    Http::fake([
        'https://id.twitch.tv/oauth2/token' => Http::response(['access_token' => 'fixture-token', 'expires_in' => 3600]),
        'https://api.igdb.com/v4/games' => Http::response([igdbFixture()]),
    ]);
    $this->withSession(personalSession());
    foreach (['PS5', 'PS4'] as $platform) {
        $this->postJson('/api/v1/games', ['title' => 'God of War', 'igdb_id' => 1942, 'platform' => $platform, 'format' => 'physical', 'status' => 'completed'])
            ->assertCreated()->assertJsonPath('data.igdb_id', 1942)->assertJsonPath('data.status', 'completed');
    }
    $this->assertDatabaseCount('games', 2);
    Queue::assertPushed(DownloadGameImage::class, 6);
    Http::assertSentCount(2);
});

it('preserves the selected header through metadata refresh', function (): void {
    $game = Game::factory()->create();
    $header = $game->images()->create(['kind' => 'screenshot', 'source_id' => 'scfixture']);
    $game->update(['header_image_id' => $header->id]);
    Queue::fake([DownloadGameImage::class]);
    Http::fake([
        'https://id.twitch.tv/oauth2/token' => Http::response(['access_token' => 'fixture-token', 'expires_in' => 3600]),
        'https://api.igdb.com/v4/games' => Http::response([igdbFixture()]),
    ]);

    $this->withSession(personalSession())->postJson('/api/v1/games/'.$game->id.'/metadata', ['igdb_id' => 1942])
        ->assertOk()->assertJsonPath('data.header_image_id', $header->id);
    $this->assertDatabaseHas('games', ['id' => $game->id, 'header_image_id' => $header->id]);
    Queue::assertPushed(DownloadGameImage::class, 3);
});

it('returns 503 when IGDB is not configured without making a request', function (): void {
    config(['nolife.igdb_client_secret' => '']);
    $this->withSession(personalSession())->getJson('/api/v1/igdb/search?query=Example')->assertServiceUnavailable();
    Http::assertNothingSent();
});

it('returns a safe error when the credentials are rejected', function (): void {
    Http::fake(['https://id.twitch.tv/oauth2/token' => Http::response(['message' => 'secret provider error'], 400)]);
    $this->withSession(personalSession())->getJson('/api/v1/igdb/search?query=Example')
        ->assertStatus(502)->assertJsonPath('message', 'IGDB credentials could not be verified.');
    Http::assertSentCount(1);
});

it('returns a safe error on connection failures without saving metadata', function (): void {
    $game = Game::factory()->create(['title' => 'Original title']);
    Http::fake(['https://id.twitch.tv/oauth2/token' => Http::failedConnection()]);
    $this->withSession(personalSession())->postJson('/api/v1/games/'.$game->id.'/metadata', ['igdb_id' => 1942])
        ->assertStatus(502)->assertJsonPath('message', 'IGDB could not be reached. Please try again.');
    $this->assertDatabaseHas('games', ['id' => $game->id, 'title' => 'Original title', 'igdb_id' => null]);
    Http::assertSentCount(1);
});

it('reports an upstream rate limit as 429', function (): void {
    Http::fake([
        'https://id.twitch.tv/oauth2/token' => Http::response(['access_token' => 'fixture-token', 'expires_in' => 3600]),
        'https://api.igdb.com/v4/games' => Http::response([], 429),
    ]);
    $this->withSession(personalSession())->getJson('/api/v1/igdb/search?query=Example')->assertTooManyRequests();
    Http::assertSentCount(2);
});

it('renews an expired token once before retrying the lookup', function (): void {
    Cache::put('nolife:igdb:token:'.hash('sha256', 'fixture-client'), 'expired-token', 300);
    Http::fake([
        'https://id.twitch.tv/oauth2/token' => Http::response(['access_token' => 'fresh-token', 'expires_in' => 3600]),
        'https://api.igdb.com/v4/games' => Http::sequence()->push([], 401)->push([igdbFixture()]),
    ]);
    $this->withSession(personalSession())->getJson('/api/v1/igdb/search?query=Example')->assertOk();
    Http::assertSentCount(3);
});

it('returns 404 for a provider ID with no matching game', function (): void {
    Http::fake([
        'https://id.twitch.tv/oauth2/token' => Http::response(['access_token' => 'fixture-token', 'expires_in' => 3600]),
        'https://api.igdb.com/v4/games' => Http::response([]),
    ]);
    $this->withSession(personalSession())->getJson('/api/v1/igdb/games/1942')->assertNotFound();
    Http::assertSentCount(2);
});
