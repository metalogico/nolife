<?php

use App\Jobs\DownloadGameImage;
use App\Models\Game;
use App\Models\GameImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    config(['nolife.username' => 'michele', 'nolife.password_hash' => Hash::make('test-password-123')]);
});

it('downloads and processes provider images into local responsive files', function (): void {
    Storage::fake('local');
    Http::preventStrayRequests();
    $image = GameImage::factory()->create(['source_url' => 'https://images.igdb.com/igdb/image/upload/t_cover_big/cofixture.jpg']);
    $source = imagecreatetruecolor(20, 30);
    ob_start();
    imagepng($source);
    $png = ob_get_clean();
    imagedestroy($source);
    Http::fake(['https://images.igdb.com/*' => Http::response($png, 200, ['Content-Type' => 'image/png'])]);
    (new DownloadGameImage($image->id))->handle();
    $image->refresh();
    Storage::disk('local')->assertExists([$image->path, $image->thumbnail_path, $image->ambient_path]);
    $this->get('/media/'.$image->id.'/full')->assertUnauthorized();
    $this->withSession(personalSession())->get('/media/'.$image->id.'/thumbnail')->assertOk()->assertHeader('Content-Type', 'image/webp');
    Http::assertSentCount(1);
});

it('rejects untrusted image download locations', function (): void {
    Http::preventStrayRequests();
    $image = GameImage::factory()->create(['source_url' => 'http://127.0.0.1/private']);
    expect(fn () => (new DownloadGameImage($image->id))->handle())->toThrow(RuntimeException::class, 'Unsupported image source.');
    Http::assertNothingSent();
});

it('accepts a manual cover and removes its file when the game is removed', function (): void {
    Storage::fake('local');
    $game = Game::factory()->create();
    $this->withSession(personalSession())->post('/api/v1/games/'.$game->id.'/cover', [
        'cover' => UploadedFile::fake()->image('cover.jpg', 400, 600),
    ], ['Accept' => 'application/json'])->assertOk()->assertJsonCount(1, 'data.images')->assertJsonPath('data.thumbnail_url', fn ($url) => str_ends_with($url, '/thumbnail'));
    $path = $game->images()->firstOrFail()->path;
    Storage::disk('local')->assertExists($path);
    expect($path)->toEndWith('.webp');
    $this->get('/media/'.$game->images()->firstOrFail()->id.'/thumbnail')->assertOk()->assertHeader('Content-Type', 'image/webp');
    $this->deleteJson('/api/v1/games/'.$game->id)->assertNoContent();
    Storage::disk('local')->assertMissing($path);
});

it('stops polling an image after all download attempts fail', function (): void {
    $image = GameImage::factory()->create();
    (new DownloadGameImage($image->id))->failed(new RuntimeException('Provider unavailable'));
    $this->withSession(personalSession())->getJson('/api/v1/games/'.$image->game_id)
        ->assertOk()->assertJsonPath('data.images_pending', false);
    expect($image->fresh()->download_failed_at)->not->toBeNull();
});
