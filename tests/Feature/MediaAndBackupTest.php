<?php

use App\Jobs\DownloadGameImage;
use App\Models\Game;
use App\Models\GameImage;
use App\Services\LibraryBackup;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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

it('backs up and verifies the database with media and restores to an isolated directory', function (): void {
    Storage::fake('local');
    $game = Game::factory()->playing()->create(['title' => 'Restore example', 'rating' => 8]);
    $image = GameImage::factory()->for($game)->create(['path' => 'media/example.webp']);
    Storage::disk('local')->put('media/example.webp', 'test image bytes');
    $temporary = sys_get_temp_dir().'/nolife-backup-test-'.Str::uuid();
    try {
        $archive = app(LibraryBackup::class)->create($temporary.'/backups');
        expect(is_file($archive))->toBeTrue();
        $this->artisan('nolife:restore', ['archive' => $archive, '--to' => $temporary.'/restored'])->assertSuccessful();
        expect(file_get_contents($temporary.'/restored/media/example.webp'))->toBe('test image bytes');
        $database = new PDO('sqlite:'.$temporary.'/restored/database.sqlite');
        expect($database->query('SELECT title FROM games')->fetchColumn())->toBe('Restore example');
        expect((int) $database->query('SELECT rating FROM games')->fetchColumn())->toBe(8);
        $database = null;
        $this->assertDatabaseHas('game_images', ['id' => $image->id, 'path' => 'media/example.webp']);
    } finally {
        File::deleteDirectory($temporary);
    }
});

it('refuses to replace the live library outside maintenance mode', function (): void {
    $this->artisan('nolife:restore', ['archive' => '/tmp/example.tar.gz', '--force' => true])->assertFailed();
});

it('reports a missing backup disk without writing an archive and recovers after the disk returns', function (): void {
    Storage::fake('local');
    $temporary = sys_get_temp_dir().'/nolife-scheduled-backup-'.Str::uuid();
    $statusPath = storage_path('app/nolife-backup-status.json');
    $previous = File::isFile($statusPath) ? File::get($statusPath) : null;
    config(['nolife.backup_path' => $temporary, 'nolife.backup_volume_id' => 'test-backup-disk']);
    $this->freezeTime();
    try {
        $this->artisan('nolife:backup')->assertFailed();
        expect(File::exists($temporary))->toBeFalse();
        expect(json_decode(File::get($statusPath), true))->toBe([
            'ok' => false, 'failed_at' => now()->toIso8601String(),
        ]);

        File::ensureDirectoryExists($temporary);
        File::put($temporary.'/.volume-id', 'test-backup-disk');
        $this->artisan('nolife:backup')->assertSuccessful();
        $status = json_decode(File::get($statusPath), true);
        expect($status['ok'])->toBeTrue();
        expect($status['completed_at'])->toBe(now()->toIso8601String());
        expect($status['archive_bytes'])->toBe(filesize($temporary.'/'.$status['archive']));
        expect(fileperms($statusPath) & 0777)->toBe(0600);
    } finally {
        File::deleteDirectory($temporary);
        if ($previous !== null) {
            File::replace($statusPath, $previous, 0600);
        } else {
            File::delete($statusPath);
        }
    }
});
