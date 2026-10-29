<?php

use App\Models\Game;
use App\Models\GameImage;
use Illuminate\Support\Facades\Hash;

beforeEach(function (): void {
    config(['nolife.username' => 'michele', 'nolife.password_hash' => Hash::make('test-password-123')]);
});

it('protects library operations with personal authentication', function (): void {
    $this->getJson('/api/v1/games')->assertUnauthorized();
    $this->postJson('/api/v1/games', ['title' => 'Example', 'platform' => 'PS5', 'format' => 'physical'])->assertUnauthorized();
    $this->getJson('/api/v1/igdb/search?query=Example')->assertUnauthorized();
});

it('creates a planned, unrated manual game and returns the stored data', function (): void {
    $this->withSession(personalSession())->postJson('/api/v1/games', [
        'title' => '  God of War  ', 'platform' => 'PS5', 'format' => 'physical',
    ])->assertCreated()->assertJsonPath('data.title', 'God of War')
        ->assertJsonPath('data.status', 'planned')->assertJsonPath('data.rating', null)
        ->assertJsonPath('data.duration_minutes', null)->assertJsonPath('data.total_played_minutes', null)->assertJsonPath('data.images', []);
    $this->assertDatabaseHas('games', ['title' => 'God of War', 'platform' => 'PS5', 'status' => 'planned']);
});

it('requires the title, platform and ownership format', function (): void {
    $this->withSession(personalSession())->postJson('/api/v1/games', [])
        ->assertUnprocessable()->assertJsonValidationErrors(['title', 'platform', 'format']);
    $this->assertDatabaseCount('games', 0);
});

it('creates a game with the status chosen by its collection section', function (string $status): void {
    $this->withSession(personalSession())->postJson('/api/v1/games', [
        'title' => 'Section example', 'platform' => 'PS5', 'format' => 'physical', 'status' => $status,
    ])->assertCreated()->assertJsonPath('data.status', $status);
    $this->assertDatabaseHas('games', ['title' => 'Section example', 'status' => $status]);
})->with(['playing', 'planned', 'completed', 'tried', 'dropped']);

it('saves a chosen gallery header and keeps it after editing personal values', function (): void {
    $game = Game::factory()->create();
    $game->images()->create(['kind' => 'artwork', 'source_id' => 'default', 'source_url' => 'https://images.igdb.com/default.jpg']);
    $chosen = GameImage::factory()->for($game)->create(['kind' => 'screenshot', 'source_url' => 'https://images.igdb.com/chosen.jpg']);

    $this->withSession(personalSession())->patchJson('/api/v1/games/'.$game->id, ['header_image_id' => $chosen->id])
        ->assertOk()->assertJsonPath('data.header_image_id', $chosen->id)
        ->assertJsonPath('data.background_url', 'https://images.igdb.com/chosen.jpg')
        ->assertJsonPath('data.ambient_url', 'https://images.igdb.com/chosen.jpg');
    $this->patchJson('/api/v1/games/'.$game->id, ['rating' => 9])->assertOk()->assertJsonPath('data.header_image_id', $chosen->id);
    $this->assertDatabaseHas('games', ['id' => $game->id, 'header_image_id' => $chosen->id, 'rating' => 9]);
    $this->deleteJson('/api/v1/games/'.$game->id)->assertNoContent();
    $this->assertDatabaseCount('game_images', 0);
});

it('rejects a header from another game or a cover with 422', function (string $kind): void {
    $game = Game::factory()->create();
    $image = $kind === 'other game'
        ? GameImage::factory()->create(['kind' => 'screenshot'])
        : GameImage::factory()->for($game)->create(['kind' => 'cover']);

    $this->withSession(personalSession())->patchJson('/api/v1/games/'.$game->id, ['header_image_id' => $image->id])
        ->assertUnprocessable()->assertJsonPath('errors.header_image_id.0', 'Choose a gallery image from this game.');
    $this->assertDatabaseHas('games', ['id' => $game->id, 'header_image_id' => null]);
})->with(['other game', 'cover']);

it('falls back to automatic artwork when the selected header is removed', function (): void {
    $game = Game::factory()->create();
    $game->images()->create(['kind' => 'artwork', 'source_id' => 'default', 'source_url' => 'https://images.igdb.com/default.jpg']);
    $chosen = GameImage::factory()->for($game)->create(['kind' => 'screenshot']);
    $game->update(['header_image_id' => $chosen->id]);
    $chosen->delete();

    $this->withSession(personalSession())->getJson('/api/v1/games/'.$game->id)->assertOk()
        ->assertJsonPath('data.header_image_id', null)->assertJsonPath('data.background_url', 'https://images.igdb.com/default.jpg');
    $this->deleteJson('/api/v1/games/'.$game->id)->assertNoContent();
    $this->assertDatabaseCount('game_images', 0);
});

it('maintains independent copies on different platforms and formats', function (): void {
    $this->withSession(personalSession());
    foreach ([['PS5', 'physical'], ['PS4', 'physical'], ['PS5', 'digital']] as [$platform, $format]) {
        $this->postJson('/api/v1/games', ['title' => 'God of War', 'platform' => $platform, 'format' => $format])->assertCreated();
    }
    $physical = Game::query()->where('platform', 'PS5')->where('format', 'physical')->firstOrFail();
    $this->patchJson('/api/v1/games/'.$physical->id, ['rating' => 10, 'status' => 'completed'])->assertOk();
    $this->assertDatabaseHas('games', ['platform' => 'PS5', 'format' => 'digital', 'rating' => null, 'status' => 'planned']);
    $this->assertDatabaseCount('games', 3);
});

it('rejects a duplicate combination without creating another record', function (): void {
    Game::factory()->create(['title' => 'God of War']);
    $this->withSession(personalSession())->postJson('/api/v1/games', ['title' => 'god of war', 'platform' => 'ps5', 'format' => 'physical'])
        ->assertUnprocessable()->assertJsonPath('errors.title.0', 'This game is already in your collection on this platform and format.');
    $this->assertDatabaseCount('games', 1);
});

it('accepts each supported play status', function (string $status): void {
    $game = Game::factory()->create();
    $this->withSession(personalSession())->patchJson('/api/v1/games/'.$game->id, ['status' => $status])
        ->assertOk()->assertJsonPath('data.status', $status);
    $this->assertDatabaseHas('games', ['id' => $game->id, 'status' => $status]);
})->with(['playing', 'planned', 'completed', 'tried', 'dropped']);

it('rejects unsupported personal values with 422', function (array $change, string $field): void {
    $game = Game::factory()->create();
    $this->withSession(personalSession())->patchJson('/api/v1/games/'.$game->id, $change)
        ->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'rating below scale' => [['rating' => 0], 'rating'],
    'rating above scale' => [['rating' => 11], 'rating'],
    'fractional rating' => [['rating' => 5.5], 'rating'],
    'unknown status' => [['status' => 'finished'], 'status'],
    'unknown format' => [['format' => 'disc'], 'format'],
    'negative duration' => [['duration_minutes' => -1], 'duration_minutes'],
    'negative played time' => [['total_played_minutes' => -1], 'total_played_minutes'],
    'fractional played minutes' => [['total_played_minutes' => 1.5], 'total_played_minutes'],
    'played time above limit' => [['total_played_minutes' => 6000001], 'total_played_minutes'],
]);

it('stores expected length and total played independently and allows clearing or zero play time', function (): void {
    $response = $this->withSession(personalSession())->postJson('/api/v1/games', [
        'title' => 'Time example', 'platform' => 'PS5', 'format' => 'physical',
        'duration_minutes' => 1530, 'total_played_minutes' => 750,
    ])->assertCreated()->assertJsonPath('data.duration_minutes', 1530)->assertJsonPath('data.total_played_minutes', 750);
    $id = $response->json('data.id');
    $this->assertDatabaseHas('games', ['id' => $id, 'duration_minutes' => 1530, 'total_played_minutes' => 750]);

    $this->patchJson('/api/v1/games/'.$id, ['total_played_minutes' => 2100])
        ->assertOk()->assertJsonPath('data.duration_minutes', 1530)->assertJsonPath('data.total_played_minutes', 2100);
    $this->assertDatabaseHas('games', ['id' => $id, 'total_played_minutes' => 2100]);
    $this->patchJson('/api/v1/games/'.$id, ['duration_minutes' => null, 'total_played_minutes' => 0])
        ->assertOk()->assertJsonPath('data.duration_minutes', null)->assertJsonPath('data.total_played_minutes', 0);
    $this->assertDatabaseHas('games', ['id' => $id, 'duration_minutes' => null, 'total_played_minutes' => 0]);
    $this->patchJson('/api/v1/games/'.$id, ['total_played_minutes' => null])->assertOk()->assertJsonPath('data.total_played_minutes', null);
    $this->assertDatabaseHas('games', ['id' => $id, 'total_played_minutes' => null]);
});

it('saves and clears rating and manual duration', function (): void {
    $game = Game::factory()->create();
    $this->withSession(personalSession())->patchJson('/api/v1/games/'.$game->id, ['rating' => 1, 'duration_minutes' => 1530])
        ->assertOk()->assertJsonPath('data.rating', 1)->assertJsonPath('data.duration_minutes', 1530);
    $this->patchJson('/api/v1/games/'.$game->id, ['rating' => 10])->assertOk()->assertJsonPath('data.rating', 10);
    $this->patchJson('/api/v1/games/'.$game->id, ['rating' => null, 'duration_minutes' => null])
        ->assertOk()->assertJsonPath('data.rating', null)->assertJsonPath('data.duration_minutes', null);
    $this->assertDatabaseHas('games', ['id' => $game->id, 'rating' => null, 'duration_minutes' => null]);
});

it('filters the collection and orders case insensitively', function (): void {
    Game::factory()->playing()->create(['title' => 'zelda', 'platform' => 'Nintendo Switch']);
    $first = Game::factory()->playing()->create(['title' => 'Bayonetta', 'platform' => 'PS3', 'format' => 'digital']);
    Game::factory()->create(['title' => 'DOOM (2016)', 'platform' => 'PS4']);
    $this->withSession(personalSession())->getJson('/api/v1/games?status=playing')
        ->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.id', $first->id);
    $this->getJson('/api/v1/games?q=DOOM&platform=PS4&format=physical')->assertOk()->assertJsonPath('meta.total', 1);
    $this->getJson('/api/v1/library')->assertOk()->assertJsonPath('total', 3)->assertJsonPath('counts.playing', 2);
});

it('treats wildcard search characters as literal text', function (): void {
    Game::factory()->create(['title' => '100% Game']);
    Game::factory()->create(['title' => 'Another Game']);
    $this->withSession(personalSession())->getJson('/api/v1/games?q=%25')->assertOk()->assertJsonPath('meta.total', 1);
});

it('lists distinct genres from the whole collection in alphabetical order', function (): void {
    Game::factory()->playing()->create(['genres' => ['Shooter', 'Adventure']]);
    Game::factory()->create(['genres' => ['Adventure', 'Role-playing (RPG)']]);
    Game::factory()->create(['genres' => null]);
    Game::factory()->create(['genres' => []]);

    $this->withSession(personalSession())->getJson('/api/v1/library')->assertOk()
        ->assertJsonPath('genres', ['Adventure', 'Role-playing (RPG)', 'Shooter']);
});

it('filters exact genres together with the other collection filters and pagination', function (): void {
    $matches = Game::factory()->count(38)->playing()->create(['genres' => ['Adventure', 'Role-playing (RPG)'], 'platform' => 'PS5', 'format' => 'physical']);
    Game::factory()->playing()->create(['genres' => ['Role-playing (RPG)'], 'platform' => 'PC', 'format' => 'digital']);
    Game::factory()->playing()->create(['genres' => ['Adventure RPG'], 'platform' => 'PS5']);
    Game::factory()->create(['genres' => ['Adventure'], 'platform' => 'PS5']);

    $query = '/api/v1/games?genre=Adventure&status=playing&platform=PS5&format=physical';
    $this->withSession(personalSession())->getJson($query)->assertOk()->assertJsonPath('meta.total', 38)
        ->assertJsonCount(36, 'data')->assertJsonPath('meta.last_page', 2);
    $this->getJson($query.'&page=2')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 38);
    $title = urlencode($matches->first()->title);
    $this->getJson($query.'&q='.$title)->assertOk()->assertJsonPath('meta.total', 1);
    $this->getJson('/api/v1/games?genre=Unknown')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/games?genre=Role-playing%20%28RPG%29')->assertOk()->assertJsonPath('meta.total', 39);
});

it('rejects invalid genre filters with 422', function (array $query): void {
    $this->withSession(personalSession())->getJson('/api/v1/games?'.http_build_query($query))
        ->assertUnprocessable()->assertJsonValidationErrors('genre');
})->with([
    'array instead of name' => [['genre' => ['Adventure']]],
    'name above limit' => [['genre' => str_repeat('a', 256)]],
]);

it('preserves both times when the shorter game editor updates other details', function (): void {
    $game = Game::factory()->create(['duration_minutes' => 1530, 'total_played_minutes' => 750]);
    $this->withSession(personalSession())->patchJson('/api/v1/games/'.$game->id, ['summary' => 'Updated summary'])
        ->assertOk()->assertJsonPath('data.duration_minutes', 1530)->assertJsonPath('data.total_played_minutes', 750);
    $this->assertDatabaseHas('games', ['id' => $game->id, 'duration_minutes' => 1530, 'total_played_minutes' => 750]);
});

it('paginates the gallery deterministically', function (): void {
    Game::factory()->count(38)->create();
    $this->withSession(personalSession())->getJson('/api/v1/games')->assertOk()->assertJsonCount(36, 'data')->assertJsonPath('meta.last_page', 2);
    $this->getJson('/api/v1/games?page=2')->assertOk()->assertJsonCount(2, 'data');
});

it('removes a game and its related image records', function (): void {
    $game = Game::factory()->hasImages(2)->create();
    $this->withSession(personalSession())->deleteJson('/api/v1/games/'.$game->id)->assertNoContent();
    $this->assertDatabaseMissing('games', ['id' => $game->id]);
    $this->assertDatabaseCount('game_images', 0);
});
