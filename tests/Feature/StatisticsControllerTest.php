<?php

use App\Models\Game;
use App\Models\GameImage;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    config(['nolife.username' => 'statistics-user', 'nolife.password_hash' => Hash::make('statistics-password')]);
    Http::preventStrayRequests();
});

it('returns 401 without a personal session even with a bearer token', function (): void {
    $this->withToken('agent-token')->getJson('/api/v1/statistics')->assertUnauthorized();
});

it('returns empty rankings and unknown ratios and averages for an empty collection', function (): void {
    $this->withSession(personalSession())->getJson('/api/v1/statistics')->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('collection.total', 0)
        ->assertJsonPath('collection.completed_percentage', null)
        ->assertJsonPath('collection.started_completed_percentage', null)
        ->assertJsonCount(5, 'collection.statuses')
        ->assertJsonPath('collection.statuses.0.count', 0)
        ->assertJsonPath('collection.statuses.0.percentage', null)
        ->assertJsonPath('playtime.total_minutes', null)
        ->assertJsonPath('playtime.recorded_count', 0)
        ->assertJsonPath('playtime.top_games', [])
        ->assertJsonPath('backlog.expected_minutes', null)
        ->assertJsonPath('backlog.short_games', [])
        ->assertJsonPath('genres.groups', [])
        ->assertJsonPath('platforms', [])
        ->assertJsonCount(2, 'formats')
        ->assertJsonPath('ratings.average', null)
        ->assertJsonCount(10, 'ratings.distribution')
        ->assertJsonPath('ratings.distribution.9.percentage', null)
        ->assertJsonPath('ratings.favorites', []);
});

it('counts all five statuses and defines started games independently of recorded hours', function (): void {
    Game::factory()->count(5)->sequence(
        ['status' => 'playing'],
        ['status' => 'planned', 'total_played_minutes' => 600],
        ['status' => 'completed'],
        ['status' => 'tried'],
        ['status' => 'dropped'],
    )->create();

    $this->withSession(personalSession())->getJson('/api/v1/statistics')->assertOk()
        ->assertJsonPath('collection.total', 5)
        ->assertJsonPath('collection.statuses', [
            ['status' => 'playing', 'count' => 1, 'percentage' => 20],
            ['status' => 'planned', 'count' => 1, 'percentage' => 20],
            ['status' => 'completed', 'count' => 1, 'percentage' => 20],
            ['status' => 'tried', 'count' => 1, 'percentage' => 20],
            ['status' => 'dropped', 'count' => 1, 'percentage' => 20],
        ])
        ->assertJsonPath('collection.completed_percentage', 20)
        ->assertJsonPath('collection.started_count', 4)
        ->assertJsonPath('collection.started_completed_percentage', 25)
        ->assertJsonPath('playtime.total_minutes', 600);
});

it('preserves the distinction between unknown and explicitly zero durations', function (): void {
    Game::factory()->create(['title' => 'Unknown']);
    Game::factory()->create(['title' => 'Zero', 'total_played_minutes' => 0, 'duration_minutes' => 0]);

    $this->withSession(personalSession())->getJson('/api/v1/statistics')->assertOk()
        ->assertJsonPath('playtime.total_minutes', 0)
        ->assertJsonPath('playtime.recorded_count', 1)
        ->assertJsonPath('playtime.top_games', [])
        ->assertJsonPath('backlog.count', 2)
        ->assertJsonPath('backlog.known_duration_count', 1)
        ->assertJsonPath('backlog.expected_minutes', 0)
        ->assertJsonPath('backlog.short_games', [])
        ->assertJsonPath('collection.started_completed_percentage', null)
        ->assertJsonPath('platforms.0.played_minutes', 0)
        ->assertJsonPath('platforms.0.played_count', 1)
        ->assertJsonPath('ratings.average', null);
});

it('aggregates the entire collection beyond pagination and ignores library filters', function (): void {
    Game::factory()->count(40)->create(['duration_minutes' => 60, 'total_played_minutes' => 0]);
    Game::factory()->create(['status' => 'completed', 'platform' => 'PC', 'total_played_minutes' => 600, 'rating' => 8]);

    $this->withSession(personalSession())->getJson('/api/v1/statistics?status=dropped&platform=Unknown&page=2&q=missing')->assertOk()
        ->assertJsonPath('collection.total', 41)
        ->assertJsonPath('collection.completed_percentage', 2.4)
        ->assertJsonPath('collection.started_completed_percentage', 100)
        ->assertJsonPath('playtime.total_minutes', 600)
        ->assertJsonPath('playtime.recorded_count', 41)
        ->assertJsonPath('backlog.count', 40)
        ->assertJsonPath('backlog.known_duration_count', 40)
        ->assertJsonPath('backlog.expected_minutes', 2400)
        ->assertJsonPath('ratings.rated_count', 1)
        ->assertJsonCount(5, 'backlog.short_games');
});

it('credits every distinct genre fully and reports platform and format samples', function (): void {
    Game::factory()->create([
        'title' => 'First', 'genres' => ['Action', 'RPG', 'Action'], 'status' => 'completed',
        'platform' => 'PC', 'format' => 'digital', 'total_played_minutes' => 120, 'rating' => 10,
    ]);
    Game::factory()->playing()->create([
        'title' => 'Second', 'genres' => ['Action'], 'total_played_minutes' => 60, 'rating' => 6,
    ]);
    Game::factory()->create(['title' => 'Unclassified', 'genres' => ['', ' ']]);

    $this->withSession(personalSession())->getJson('/api/v1/statistics')->assertOk()
        ->assertJsonPath('genres.classified_count', 2)
        ->assertJsonCount(2, 'genres.groups')
        ->assertJsonPath('genres.groups.0.name', 'Action')
        ->assertJsonPath('genres.groups.0.count', 2)
        ->assertJsonPath('genres.groups.0.played_minutes', 180)
        ->assertJsonPath('genres.groups.0.average_rating', 8)
        ->assertJsonPath('genres.groups.0.rated_count', 2)
        ->assertJsonPath('genres.groups.1.name', 'RPG')
        ->assertJsonPath('genres.groups.1.played_minutes', 120)
        ->assertJsonPath('genres.groups.1.average_rating', 10)
        ->assertJsonPath('genres.groups.1.rated_count', 1)
        ->assertJsonPath('platforms.0.name', 'PS5')
        ->assertJsonPath('platforms.0.count', 2)
        ->assertJsonPath('platforms.0.played_minutes', 60)
        ->assertJsonPath('platforms.0.played_count', 1)
        ->assertJsonPath('platforms.0.completed_percentage', 0)
        ->assertJsonPath('platforms.0.average_rating', 6)
        ->assertJsonPath('platforms.0.rated_count', 1)
        ->assertJsonPath('platforms.1.completed_percentage', 100)
        ->assertJsonPath('formats', [
            ['format' => 'physical', 'count' => 2, 'percentage' => 66.7],
            ['format' => 'digital', 'count' => 1, 'percentage' => 33.3],
        ]);
});

it('returns unknown group playtime and averages when no values are recorded', function (): void {
    Game::factory()->create(['genres' => ['Adventure']]);

    $this->withSession(personalSession())->getJson('/api/v1/statistics')->assertOk()
        ->assertJsonPath('genres.groups.0.played_minutes', null)
        ->assertJsonPath('genres.groups.0.average_rating', null)
        ->assertJsonPath('genres.groups.0.rated_count', 0)
        ->assertJsonPath('platforms.0.played_minutes', null)
        ->assertJsonPath('backlog.known_duration_count', 0)
        ->assertJsonPath('backlog.expected_minutes', null);
});

it('returns every rating bin and averages only rated copies', function (): void {
    Game::factory()->count(4)->sequence(
        ['rating' => 1], ['rating' => 10], ['rating' => 10], ['rating' => null],
    )->create();

    $this->withSession(personalSession())->getJson('/api/v1/statistics')->assertOk()
        ->assertJsonPath('ratings.average', 7)
        ->assertJsonPath('ratings.rated_count', 3)
        ->assertJsonCount(10, 'ratings.distribution')
        ->assertJsonPath('ratings.distribution.0', ['rating' => 1, 'count' => 1, 'percentage' => 33.3])
        ->assertJsonPath('ratings.distribution.1', ['rating' => 2, 'count' => 0, 'percentage' => 0])
        ->assertJsonPath('ratings.distribution.9', ['rating' => 10, 'count' => 2, 'percentage' => 66.7]);
});

it('ranks five most played copies and favorites by value then title then id', function (): void {
    $games = Game::factory()->count(7)->sequence(
        ['title' => 'Zulu', 'total_played_minutes' => 1200, 'rating' => 10],
        ['title' => 'Echo', 'total_played_minutes' => 600, 'rating' => 9],
        ['title' => 'alpha', 'platform' => 'PC', 'total_played_minutes' => 600, 'rating' => 9],
        ['title' => 'alpha', 'platform' => 'PS5', 'total_played_minutes' => 600, 'rating' => 9],
        ['title' => 'Beta', 'total_played_minutes' => 600, 'rating' => 9],
        ['title' => 'Delta', 'total_played_minutes' => 600, 'rating' => 9],
        ['title' => 'Foxtrot', 'total_played_minutes' => 600, 'rating' => 9],
    )->create();
    $expectedIds = [$games[0]->id, $games[2]->id, $games[3]->id, $games[4]->id, $games[5]->id];

    $response = $this->withSession(personalSession())->getJson('/api/v1/statistics')->assertOk();

    expect(array_column($response->json('playtime.top_games'), 'id'))->toBe($expectedIds);
    expect(array_column($response->json('ratings.favorites'), 'id'))->toBe($expectedIds);
    $response->assertJsonPath('playtime.total_minutes', 4800)
        ->assertJsonPath('playtime.top_games.1.platform', 'PC')
        ->assertJsonPath('playtime.top_games.2.platform', 'PS5');
});

it('suggests only the five shortest planned games with a positive known length', function (): void {
    $games = Game::factory()->count(7)->sequence(
        ['title' => 'Zulu', 'duration_minutes' => 300],
        ['title' => 'Echo', 'duration_minutes' => 120],
        ['title' => 'alpha', 'platform' => 'PC', 'duration_minutes' => 60],
        ['title' => 'alpha', 'platform' => 'PS5', 'duration_minutes' => 60],
        ['title' => 'Beta', 'duration_minutes' => 60],
        ['title' => 'Delta', 'duration_minutes' => 60],
        ['title' => 'Foxtrot', 'duration_minutes' => 120],
    )->create();
    Game::factory()->create(['title' => 'Unknown']);
    Game::factory()->create(['title' => 'Zero', 'duration_minutes' => 0]);
    Game::factory()->playing()->create(['title' => 'Already started', 'duration_minutes' => 1]);

    $response = $this->withSession(personalSession())->getJson('/api/v1/statistics')->assertOk()
        ->assertJsonPath('backlog.count', 9)
        ->assertJsonPath('backlog.known_duration_count', 8)
        ->assertJsonPath('backlog.expected_minutes', 780);

    expect(array_column($response->json('backlog.short_games'), 'id'))
        ->toBe([$games[2]->id, $games[3]->id, $games[4]->id, $games[5]->id, $games[1]->id]);
});

it('returns only cover thumbnails for ranked games and handles missing covers', function (): void {
    Storage::fake('local');
    $game = Game::factory()->create(['total_played_minutes' => 60]);
    GameImage::factory()->for($game)->create(['kind' => 'artwork', 'source_url' => 'https://example.test/artwork.jpg']);
    GameImage::factory()->for($game)->create(['kind' => 'cover', 'source_url' => 'https://example.test/cover.jpg']);
    Game::factory()->create(['total_played_minutes' => 30]);

    $this->withSession(personalSession())->getJson('/api/v1/statistics')->assertOk()
        ->assertJsonPath('playtime.top_games.0.thumbnail_url', 'https://example.test/cover.jpg')
        ->assertJsonPath('playtime.top_games.1.thumbnail_url', null)
        ->assertJsonMissingPath('playtime.top_games.0.images');
});

it('recalculates statistics after a game is edited and deleted without stale results', function (): void {
    Storage::fake('local');
    $game = Game::factory()->create(['duration_minutes' => 120, 'total_played_minutes' => 60, 'rating' => 5]);
    $this->withSession(personalSession())->getJson('/api/v1/statistics')->assertOk()
        ->assertJsonPath('backlog.count', 1)->assertJsonPath('ratings.average', 5);

    $this->patchJson('/api/v1/games/'.$game->id, [
        'status' => 'completed', 'total_played_minutes' => 180, 'rating' => 9,
    ])->assertOk();
    $this->assertDatabaseHas('games', ['id' => $game->id, 'status' => 'completed', 'total_played_minutes' => 180, 'rating' => 9]);
    $this->getJson('/api/v1/statistics')->assertOk()
        ->assertJsonPath('backlog.count', 0)->assertJsonPath('playtime.total_minutes', 180)
        ->assertJsonPath('collection.completed_percentage', 100)->assertJsonPath('ratings.average', 9);

    $this->deleteJson('/api/v1/games/'.$game->id)->assertNoContent();
    $this->assertModelMissing($game);
    $this->getJson('/api/v1/statistics')->assertOk()
        ->assertJsonPath('collection.total', 0)->assertJsonPath('playtime.top_games', [])
        ->assertJsonPath('ratings.average', null);
});
