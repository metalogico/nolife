<?php

use App\Jobs\DownloadGameImage;
use App\Models\Game;
use App\Models\GameImage;
use App\Models\McpSetting;
use App\Services\GameImageFiles;
use App\Services\LibraryTransfer;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    config(['nolife.username' => 'transfer-user', 'nolife.password_hash' => Hash::make('transfer-password')]);
});

function transferGame(): Game
{
    $game = Game::factory()->playing()->create([
        'title' => 'Café <Adventure> & friends', 'rating' => 8, 'duration_minutes' => 1230,
        'total_played_minutes' => 645, 'summary' => 'A personal summary.', 'release_year' => 2024,
        'developer' => 'Example developer', 'publisher' => 'Example publisher',
        'genres' => ['Adventure', 'Role-playing (RPG)'], 'igdb_id' => 1942,
        'igdb_url' => 'https://www.igdb.com/games/example',
        'metadata_fetched_at' => Carbon::parse('2026-10-20T12:00:00Z')->setTimezone(config('app.timezone')),
        'created_at' => Carbon::parse('2026-10-01T08:00:00Z')->setTimezone(config('app.timezone')),
        'updated_at' => Carbon::parse('2026-10-21T09:00:00Z')->setTimezone(config('app.timezone')),
    ]);
    foreach (['cover', 'artwork'] as $kind) {
        $paths = app(GameImageFiles::class)->store(
            UploadedFile::fake()->image('source.png', 600, 400)->getContent(),
            'media/fixtures/'.Str::uuid(), $kind === 'cover',
        );
        $image = GameImage::factory()->for($game)->create([
            'kind' => $kind, 'source_id' => $kind.'-source',
            'source_url' => 'https://images.igdb.com/igdb/image/upload/example.jpg',
            'position' => $kind === 'cover' ? 0 : 1, ...$paths,
        ]);
        if ($kind === 'artwork') {
            $game->header_image_id = $image->id;
            Game::withoutTimestamps(fn (): bool => $game->save());
        }
    }

    return $game->refresh()->load('images');
}

function transferUpload(string $path): UploadedFile
{
    return new UploadedFile($path, 'library.zip', 'application/zip', null, true);
}

function transferManifest(string $path): array
{
    $zip = new ZipArchive;
    $zip->open($path);
    try {
        return json_decode($zip->getFromName('library.json'), true, 512, JSON_THROW_ON_ERROR);
    } finally {
        $zip->close();
    }
}

function changeTransferArchive(string $path, Closure $change): void
{
    $zip = new ZipArchive;
    $zip->open($path);
    try {
        $manifest = json_decode($zip->getFromName('library.json'), true, 512, JSON_THROW_ON_ERROR);
        $change($manifest, $zip);
        $zip->addFromString('library.json', json_encode($manifest, JSON_THROW_ON_ERROR));
    } finally {
        $zip->close();
    }
}

it('returns 401 for every transfer operation without a browser session', function (string $method, string $path): void {
    $this->json($method, $path)->assertUnauthorized();
})->with([
    ['POST', '/api/v1/settings/library/export'],
    ['GET', '/api/v1/settings/library/export/11111111-1111-4111-8111-111111111111'],
    ['POST', '/api/v1/settings/library/import'],
]);

it('returns 401 when an MCP token is used to replace the library', function (): void {
    $this->withToken('agent-key')->postJson('/api/v1/settings/library/import')->assertUnauthorized();
});

it('downloads one private ZIP with the complete library and all image variants', function (): void {
    Storage::fake('local');
    $this->freezeTime();
    $game = transferGame();
    McpSetting::factory()->create(['read_token_hash' => hash('sha256', 'private-agent-key')]);

    $export = $this->withSession(personalSession())->postJson('/api/v1/settings/library/export')
        ->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    $download = $this->get($export->json('download_url'))->assertOk()->assertDownload()
        ->assertHeader('Content-Type', 'application/zip')->assertHeader('Cache-Control', 'no-store, private');
    $path = $download->baseResponse->getFile()->getPathname();
    $manifest = transferManifest($path);

    expect($manifest)->toHaveKeys(['application', 'version', 'exported_at', 'games', 'files']);
    expect($manifest['application'])->toBe('NoLife');
    expect($manifest['version'])->toBe(1);
    expect($manifest['games'])->toHaveCount(1);
    expect($manifest['games'][0])->toMatchArray([
        'title' => 'Café <Adventure> & friends', 'rating' => 8,
        'duration_minutes' => 1230, 'total_played_minutes' => 645,
        'header_image_id' => $game->header_image_id,
        'genres' => ['Adventure', 'Role-playing (RPG)'],
        'created_at' => '2026-10-01T08:00:00.000000Z', 'updated_at' => '2026-10-21T09:00:00.000000Z',
    ]);
    expect(json_encode($manifest))->not->toContain('private-agent-key', hash('sha256', 'private-agent-key'), 'identity_key', 'password_hash');
    expect($manifest['files'])->toHaveCount(6);
    $zip = new ZipArchive;
    $zip->open($path);
    foreach ($manifest['files'] as $name => $file) {
        expect($zip->getFromName($name))->toBe(Storage::disk('local')->get($name));
    }
    $zip->close();
    ob_start();
    $download->baseResponse->sendContent();
    $bytes = ob_get_clean();
    expect($bytes)->toStartWith('PK');
    expect(is_file($path))->toBeFalse();
});

it('replaces a different library with all personal data and images while keeping local settings', function (): void {
    Storage::fake('local');
    Http::preventStrayRequests();
    $source = transferGame();
    Game::factory()->create(['title' => $source->title, 'format' => 'digital']);
    $transfer = app(LibraryTransfer::class);
    $archive = $transfer->exportPath($transfer->export());
    $before = transferManifest($archive);
    Game::query()->delete();
    $old = Game::factory()->create(['title' => 'Destination only']);
    $oldImage = GameImage::factory()->for($old)->create(['path' => 'media/destination.webp']);
    Storage::disk('local')->put($oldImage->path, 'destination bytes');
    $settings = McpSetting::factory()->create(['enabled' => true, 'write_token_hash' => hash('sha256', 'destination-key')]);
    $settingsBefore = $settings->fresh()->getRawOriginal();

    $this->withSession(personalSession())->post('/api/v1/settings/library/import', [
        'archive' => transferUpload($archive), 'confirm' => '1',
    ], ['Accept' => 'application/json'])->assertOk()->assertExactJson(['games' => 2, 'images' => 2]);

    $this->assertDatabaseCount('games', 2);
    $this->assertDatabaseCount('game_images', 2);
    $this->assertModelMissing($old);
    Storage::disk('local')->assertMissing('media/destination.webp');
    $imported = Game::query()->where('format', 'physical')->firstOrFail();
    expect($imported->id)->toBeGreaterThan($old->id);
    expect($imported->rating)->toBe(8);
    expect($imported->total_played_minutes)->toBe(645);
    expect($imported->images()->whereKey($imported->header_image_id)->firstOrFail()->kind)->toBe('artwork');
    expect($settings->fresh()->getRawOriginal())->toBe($settingsBefore);
    (new DownloadGameImage($oldImage->id))->handle();
    Http::assertNothingSent();
    $after = transferManifest($transfer->exportPath($transfer->export()));
    foreach ($before['games'] as $index => $game) {
        expect(collect($after['games'][$index])->except(['id', 'images', 'header_image_id'])->all())
            ->toBe(collect($game)->except(['id', 'images', 'header_image_id'])->all());
        foreach ($game['images'] as $imageIndex => $image) {
            $importedImage = $after['games'][$index]['images'][$imageIndex];
            expect(collect($importedImage)->except(['id', 'path', 'thumbnail_path', 'ambient_path'])->all())
                ->toBe(collect($image)->except(['id', 'path', 'thumbnail_path', 'ambient_path'])->all());
            foreach (['path', 'thumbnail_path', 'ambient_path'] as $field) {
                expect($after['files'][$importedImage[$field]]['sha256'])->toBe($before['files'][$image[$field]]['sha256']);
            }
        }
    }
    foreach ($after['files'] as $path => $file) {
        expect(hash('sha256', Storage::disk('local')->get($path)))->toBe($file['sha256']);
    }
});

it('returns 422 before modifying anything when confirmation is missing', function (): void {
    Storage::fake('local');
    $game = Game::factory()->create();

    $this->withSession(personalSession())->postJson('/api/v1/settings/library/import')
        ->assertUnprocessable()->assertJsonValidationErrors(['archive', 'confirm']);

    $this->assertModelExists($game);
    Storage::disk('local')->assertDirectoryEmpty('media');
});

it('returns 422 without replacing games when the archive is damaged or unsupported', function (Closure $damage): void {
    Storage::fake('local');
    $source = transferGame();
    $transfer = app(LibraryTransfer::class);
    $archive = $transfer->exportPath($transfer->export());
    $destination = Game::factory()->create(['title' => 'Keep this game']);
    $beforeFiles = Storage::disk('local')->allFiles('media');
    $damage($archive);

    $this->withSession(personalSession())->post('/api/v1/settings/library/import', [
        'archive' => transferUpload($archive), 'confirm' => '1',
    ], ['Accept' => 'application/json'])->assertUnprocessable();

    $this->assertModelExists($source);
    $this->assertModelExists($destination);
    $this->assertDatabaseCount('games', 2);
    expect(Storage::disk('local')->allFiles('media'))->toBe($beforeFiles);
})->with([
    'invalid ZIP' => [function (string $path): void {
        file_put_contents($path, 'not a ZIP archive');
    }],
    'invalid JSON' => [function (string $path): void {
        $zip = new ZipArchive;
        $zip->open($path);
        $zip->addFromString('library.json', '{broken');
        $zip->close();
    }],
    'damaged CRC' => [function (string $path): void {
        $zip = new ZipArchive;
        $zip->open($path);
        $data = $zip->getFromName(array_key_first(transferManifest($path)['files']));
        $zip->close();
        $bytes = file_get_contents($path);
        $position = strpos($bytes, $data);
        $bytes[$position + 8] = chr(ord($bytes[$position + 8]) ^ 1);
        file_put_contents($path, $bytes);
    }],
    'oversized JSON' => [function (string $path): void {
        $zip = new ZipArchive;
        $zip->open($path);
        $zip->addFromString('library.json', str_repeat(' ', 16777217));
        $zip->close();
    }],
    'wrong application' => [function (string $path): void {
        changeTransferArchive($path, function (array &$data): void {
            $data['application'] = 'Other';
        });
    }],
    'future format' => [function (string $path): void {
        changeTransferArchive($path, function (array &$data): void {
            $data['version'] = 99;
        });
    }],
    'missing image' => [function (string $path): void {
        changeTransferArchive($path, function (array &$data, ZipArchive $zip): void {
            $zip->deleteName(array_key_first($data['files']));
        });
    }],
    'wrong checksum' => [function (string $path): void {
        changeTransferArchive($path, function (array &$data): void {
            $data['files'][array_key_first($data['files'])]['sha256'] = str_repeat('0', 64);
        });
    }],
    'invalid image bytes' => [function (string $path): void {
        changeTransferArchive($path, function (array &$data, ZipArchive $zip): void {
            $name = array_key_first($data['files']);
            $zip->addFromString($name, 'not an image');
            $data['files'][$name] = ['size' => 12, 'sha256' => hash('sha256', 'not an image')];
        });
    }],
    'path traversal' => [function (string $path): void {
        changeTransferArchive($path, function (array &$data, ZipArchive $zip): void {
            $zip->addFromString('../escape.webp', 'unsafe');
        });
    }],
    'absolute path' => [function (string $path): void {
        changeTransferArchive($path, function (array &$data, ZipArchive $zip): void {
            $zip->addFromString('/media/escape.webp', 'unsafe');
        });
    }],
    'symlink' => [function (string $path): void {
        changeTransferArchive($path, function (array &$data, ZipArchive $zip): void {
            $name = array_key_first($data['files']);
            $zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, 0120777 << 16);
        });
    }],
    'duplicate game' => [function (string $path): void {
        changeTransferArchive($path, function (array &$data): void {
            $data['games'][] = $data['games'][0];
        });
    }],
    'invalid header' => [function (string $path): void {
        changeTransferArchive($path, function (array &$data): void {
            $data['games'][0]['header_image_id'] = 99999;
        });
    }],
    'invalid game data' => [function (string $path): void {
        changeTransferArchive($path, function (array &$data): void {
            $data['games'][0]['status'] = 'invalid';
        });
    }],
    'invalid genre structure' => [function (string $path): void {
        changeTransferArchive($path, function (array &$data): void {
            $data['games'][0]['genres'] = ['unexpected' => 'Adventure'];
        });
    }],
    'unreferenced file' => [function (string $path): void {
        changeTransferArchive($path, function (array &$data, ZipArchive $zip): void {
            $zip->addFromString('media/extra.webp', 'extra');
        });
    }],
]);

it('rolls back the library and staged images if database persistence fails', function (): void {
    Storage::fake('local');
    $source = transferGame();
    $transfer = app(LibraryTransfer::class);
    $archive = $transfer->exportPath($transfer->export());
    $beforeFiles = Storage::disk('local')->allFiles('media');
    DB::unprepared("CREATE TRIGGER reject_transfer BEFORE INSERT ON games BEGIN SELECT RAISE(ABORT, 'test persistence failure'); END");

    $this->withSession(personalSession())->post('/api/v1/settings/library/import', [
        'archive' => transferUpload($archive), 'confirm' => '1',
    ], ['Accept' => 'application/json'])->assertServerError();

    $this->assertModelExists($source);
    $this->assertDatabaseCount('games', 1);
    $this->assertDatabaseCount('game_images', 2);
    expect(Storage::disk('local')->allFiles('media'))->toBe($beforeFiles);
});

it('replaces a populated library with an exported empty library', function (): void {
    Storage::fake('local');
    $transfer = app(LibraryTransfer::class);
    $archive = $transfer->exportPath($transfer->export());
    transferGame();

    $this->withSession(personalSession())->post('/api/v1/settings/library/import', [
        'archive' => transferUpload($archive), 'confirm' => '1',
    ], ['Accept' => 'application/json'])->assertOk()->assertExactJson(['games' => 0, 'images' => 0]);

    $this->assertDatabaseEmpty('games');
    $this->assertDatabaseEmpty('game_images');
    Storage::disk('local')->assertDirectoryEmpty('media');
});

it('returns 422 without creating an incomplete export when an image is unavailable', function (bool $missing): void {
    Storage::fake('local');
    $game = Game::factory()->create();
    GameImage::factory()->for($game)->create(['path' => $missing ? 'media/missing.webp' : null]);

    $this->withSession(personalSession())->postJson('/api/v1/settings/library/export')->assertUnprocessable();

    Storage::disk('local')->assertDirectoryEmpty('transfers');
})->with(['pending download' => false, 'missing file' => true]);

it('returns 404 for missing exports and refuses invalid download identifiers', function (): void {
    Storage::fake('local');
    $this->withSession(personalSession())->getJson('/api/v1/settings/library/export/'.Str::uuid())->assertNotFound();
    $this->getJson('/api/v1/settings/library/export/not-an-id')->assertNotFound();
});

it('returns 422 for uploads exceeding 1 GB without changing the library', function (): void {
    $game = Game::factory()->create();

    $this->withSession(personalSession())->post('/api/v1/settings/library/import', [
        'archive' => UploadedFile::fake()->create('library.zip', 1048577, 'application/zip'), 'confirm' => '1',
    ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('archive');

    $this->assertModelExists($game);
});

it('returns 422 when library replacement has not been accepted', function (): void {
    $game = Game::factory()->create();

    $this->withSession(personalSession())->post('/api/v1/settings/library/import', [
        'archive' => UploadedFile::fake()->create('library.zip', 1, 'application/zip'), 'confirm' => '0',
    ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('confirm');

    $this->assertModelExists($game);
});

it('returns 422 instead of exporting a damaged local image', function (): void {
    Storage::fake('local');
    $game = Game::factory()->create();
    GameImage::factory()->for($game)->create(['path' => 'media/damaged.webp']);
    Storage::disk('local')->put('media/damaged.webp', 'broken image');

    $this->withSession(personalSession())->postJson('/api/v1/settings/library/export')->assertUnprocessable();

    Storage::disk('local')->assertDirectoryEmpty('transfers');
    expect(Storage::disk('local')->get('media/damaged.webp'))->toBe('broken image');
});
