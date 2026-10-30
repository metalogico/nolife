<?php

namespace App\Services;

use App\Models\Game;
use App\Models\GameImage;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use JsonException;
use RuntimeException;
use Throwable;
use ZipArchive;

class LibraryTransfer
{
    public const int MAX_ARCHIVE_BYTES = 1073741824;

    private const int MAX_JSON_BYTES = 16777216;

    private const array GAME_FIELDS = [
        'id', 'title', 'platform', 'format', 'status', 'rating', 'duration_minutes',
        'total_played_minutes', 'summary', 'release_year', 'developer', 'publisher',
        'genres', 'igdb_id', 'igdb_url', 'metadata_fetched_at', 'header_image_id',
        'created_at', 'updated_at',
    ];

    private const array IMAGE_FIELDS = [
        'id', 'kind', 'source_id', 'source_url', 'path', 'thumbnail_path',
        'ambient_path', 'position', 'download_failed_at', 'created_at', 'updated_at',
    ];

    private const array IMAGE_PATHS = ['path', 'thumbnail_path', 'ambient_path'];

    public function export(): string
    {
        return app(LibraryLock::class)->run(function (): string {
            $directory = Storage::disk('local')->path('transfers');
            File::ensureDirectoryExists($directory, 0700);
            foreach (File::files($directory) as $file) {
                if ($file->getMTime() < time() - 86400 && $file->getExtension() === 'zip') {
                    File::delete($file->getPathname());
                }
            }
            $id = (string) Str::uuid();
            $path = $this->exportPath($id);
            $zip = new ZipArchive;
            if ($zip->open($path, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
                throw new RuntimeException('The library archive could not be created.');
            }
            try {
                $files = [];
                $games = [];
                $totalBytes = 0;
                $manifestBytes = 512;
                foreach (Game::query()->with('images')->orderBy('id')->lazy(100) as $game) {
                    if (count($games) >= 20000 || $game->images->count() > 1000) {
                        $this->invalid('The library is too large for this archive format.');
                    }
                    $data = $this->exportDates(Arr::only($game->attributesToArray(), self::GAME_FIELDS), ['created_at', 'updated_at', 'metadata_fetched_at']);
                    $data['images'] = [];
                    foreach ($game->images as $image) {
                        if (! $image->path) {
                            $this->invalid('Some images are not stored locally yet. Finish their downloads before exporting.');
                        }
                        $data['images'][] = $this->exportDates(Arr::only($image->attributesToArray(), self::IMAGE_FIELDS), ['created_at', 'updated_at', 'download_failed_at']);
                        foreach (self::IMAGE_PATHS as $field) {
                            $relative = $image->{$field};
                            if ($relative === null || isset($files[$relative])) {
                                continue;
                            }
                            $this->validateMediaPath($relative);
                            $source = Storage::disk('local')->path($relative);
                            if (! is_file($source) || is_link($source)) {
                                $this->invalid('A library image is missing. Restore it before exporting.');
                            }
                            $info = @getimagesize($source);
                            if (! $info || $info[2] !== IMAGETYPE_WEBP) {
                                $this->invalid('A library image is damaged. Restore it before exporting.');
                            }
                            $size = filesize($source);
                            $totalBytes += $size;
                            if ($size > 16777216 || $totalBytes > self::MAX_ARCHIVE_BYTES - self::MAX_JSON_BYTES) {
                                $this->invalid('The library exceeds the transfer size limits (1 GB ZIP, 16 MB per image).');
                            }
                            $files[$relative] = ['size' => $size, 'sha256' => hash_file('sha256', $source)];
                            $manifestBytes += strlen($relative) + 128;
                            if ($manifestBytes > self::MAX_JSON_BYTES || count($files) > 49999) {
                                $this->invalid('The library is too large for this archive format.');
                            }
                            if (! $zip->addFile($source, $relative) || ! $zip->setCompressionName($relative, ZipArchive::CM_STORE)) {
                                throw new RuntimeException('An image could not be added to the archive.');
                            }
                        }
                    }
                    $manifestBytes += strlen(json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
                    if ($manifestBytes > self::MAX_JSON_BYTES) {
                        $this->invalid('The library is too large for this archive format.');
                    }
                    $games[] = $data;
                }
                $manifest = [
                    'application' => 'NoLife', 'version' => 1, 'exported_at' => now()->toIso8601String(),
                    'games' => $games, 'files' => $files,
                ];
                $this->validateManifest($manifest);
                $manifest['files'] = (object) $files;
                $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                if (strlen($json) > self::MAX_JSON_BYTES || count($files) > 49999) {
                    $this->invalid('The library is too large for this archive format.');
                }
                if (! $zip->addFromString('library.json', $json) || ! $zip->close()) {
                    throw new RuntimeException('The library archive could not be saved.');
                }
                clearstatcache(true, $path);
                if (filesize($path) > self::MAX_ARCHIVE_BYTES) {
                    $this->invalid('The library exceeds the 1 GB transfer limit.');
                }
                chmod($path, 0600);

                return $id;
            } catch (Throwable $error) {
                unset($zip);
                File::delete($path);
                throw $error;
            }
        });
    }

    public function exportPath(string $id): string
    {
        return Storage::disk('local')->path('transfers/'.$id.'.zip');
    }

    /** @return array{games: int, images: int} */
    public function import(string $path): array
    {
        $zip = new ZipArchive;
        if (filesize($path) > self::MAX_ARCHIVE_BYTES || $zip->open($path, ZipArchive::RDONLY) !== true) {
            $this->invalid('Choose a valid NoLife ZIP archive up to 1 GB.');
        }
        $prefix = 'media/import-'.Str::uuid();
        $committed = false;
        try {
            $entries = $this->entries($zip);
            $json = $zip->getFromName('library.json', self::MAX_JSON_BYTES + 1);
            if (! is_string($json)) {
                $this->invalid('The archive does not contain library.json.');
            }
            try {
                $manifest = json_decode($json, true, 128, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                $this->invalid('The library JSON is invalid.');
            }
            $this->validateManifest($manifest);
            if (array_diff(array_keys($entries), ['library.json', ...array_keys($manifest['files'])]) !== []
                || array_diff(array_keys($manifest['files']), array_keys($entries)) !== []) {
                $this->invalid('The image files do not match the library manifest.');
            }
            $paths = $this->stageImages($zip, $manifest['files'], $entries, $prefix);
            $result = app(LibraryLock::class)->run(function () use ($manifest, $paths): array {
                $previousPaths = GameImage::query()->select(['id', ...self::IMAGE_PATHS])->lazy(100)->flatMap(
                    fn (GameImage $image): array => array_filter([
                        $image->path, $image->thumbnail_path, $image->ambient_path,
                    ]),
                )->unique()->values()->all();
                $result = DB::transaction(function () use ($manifest, $paths): array {
                    Game::query()->delete();
                    $imageCount = 0;
                    foreach ($manifest['games'] as $data) {
                        $game = new Game;
                        $game->forceFill($this->importDates(Arr::except(Arr::only($data, self::GAME_FIELDS), ['id', 'header_image_id']), ['created_at', 'updated_at', 'metadata_fetched_at']));
                        $game->save();
                        foreach ($data['images'] as $imageData) {
                            $attributes = $this->importDates(Arr::except(Arr::only($imageData, self::IMAGE_FIELDS), ['id']), ['created_at', 'updated_at', 'download_failed_at']);
                            foreach (self::IMAGE_PATHS as $field) {
                                $attributes[$field] = $imageData[$field] === null ? null : $paths[$imageData[$field]];
                            }
                            $image = $game->images()->make();
                            $image->forceFill($attributes)->save();
                            $imageCount++;
                            if ($data['header_image_id'] === $imageData['id']) {
                                DB::table('games')->where('id', $game->id)->update(['header_image_id' => $image->id]);
                            }
                        }
                    }

                    return ['games' => count($manifest['games']), 'images' => $imageCount];
                });
                try {
                    foreach (array_chunk($previousPaths, 100) as $chunk) {
                        Storage::disk('local')->delete($chunk);
                    }
                } catch (Throwable $error) {
                    report($error);
                }

                return $result;
            });
            $committed = true;

            return $result;
        } finally {
            $zip->close();
            if (! $committed) {
                Storage::disk('local')->deleteDirectory($prefix);
            }
        }
    }

    /** @return array<string, int> */
    private function entries(ZipArchive $zip): array
    {
        if ($zip->numFiles > 50000) {
            $this->invalid('The archive contains too many files.');
        }
        $entries = [];
        $total = 0;
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $entry = $zip->statIndex($index);
            if ($entry === false) {
                $this->invalid('The ZIP archive could not be read.');
            }
            $name = $entry['name'];
            $isJson = $name === 'library.json';
            if (! $isJson) {
                $this->validateMediaPath($name);
            }
            $system = 0;
            $attributes = 0;
            $zip->getExternalAttributesIndex($index, $system, $attributes);
            if (isset($entries[$name]) || (($attributes >> 16) & 0170000) === 0120000
                || ($entry['encryption_method'] ?? 0) !== ZipArchive::EM_NONE
                || $entry['size'] > ($isJson ? self::MAX_JSON_BYTES : 16777216)) {
                $this->invalid('The archive contains an unsupported entry.');
            }
            $total += $entry['size'];
            if ($total > 4294967296) {
                $this->invalid('The expanded archive exceeds the 4 GB limit.');
            }
            $entries[$name] = $entry['size'];
        }
        if (! isset($entries['library.json'])) {
            $this->invalid('The archive does not contain library.json.');
        }

        return $entries;
    }

    private function validateMediaPath(string $path): void
    {
        if (! preg_match('~\Amedia/[a-zA-Z0-9/_-]+\.webp\z~D', $path)) {
            $this->invalid('The archive contains an unsupported image path.');
        }
    }

    private function validateManifest(mixed $manifest): void
    {
        if (! is_array($manifest) || ($manifest['application'] ?? null) !== 'NoLife' || ($manifest['version'] ?? null) !== 1
            || ! is_array($manifest['games'] ?? null) || ! array_is_list($manifest['games'])
            || ! is_array($manifest['files'] ?? null) || count($manifest['games']) > 20000) {
            $this->invalid('This is not a supported NoLife library archive.');
        }
        $gameIds = [];
        $imageIds = [];
        $identities = [];
        $referencedFiles = [];
        foreach ($manifest['games'] as $game) {
            if (! is_array($game) || array_diff(self::GAME_FIELDS, array_keys($game)) !== []) {
                $this->invalid('A game record is incomplete.');
            }
            Validator::make($game, [
                ...GameInput::rules(true),
                'id' => ['required', 'integer', 'min:1'],
                'status' => ['required', Rule::in(['playing', 'planned', 'completed', 'tried', 'dropped'])],
                'genres' => ['nullable', 'array', 'list', 'max:100'], 'genres.*' => ['string', 'max:255'],
                'igdb_url' => ['nullable', 'url:http,https', 'max:255'],
                'metadata_fetched_at' => ['nullable', 'date'],
                'created_at' => ['required', 'date'], 'updated_at' => ['required', 'date'],
                'images' => ['present', 'array', 'list', 'max:1000'],
            ])->validate();
            $identity = Game::identityKey($game['title'], $game['platform'], $game['format'], $game['igdb_id']);
            if (isset($gameIds[$game['id']]) || isset($identities[$identity])) {
                $this->invalid('The archive contains duplicate games.');
            }
            $gameIds[$game['id']] = true;
            $identities[$identity] = true;
            $sources = [];
            $headerFound = $game['header_image_id'] === null;
            foreach ($game['images'] as $image) {
                if (! is_array($image) || array_diff(self::IMAGE_FIELDS, array_keys($image)) !== []) {
                    $this->invalid('An image record is incomplete.');
                }
                Validator::make($image, [
                    'id' => ['required', 'integer', 'min:1'],
                    'kind' => ['required', Rule::in(['cover', 'artwork', 'screenshot'])],
                    'source_id' => ['required', 'string', 'max:100'],
                    'source_url' => ['nullable', 'url:https', 'max:500'],
                    'path' => ['required', 'string', 'max:255'],
                    'thumbnail_path' => ['nullable', 'string', 'max:255'],
                    'ambient_path' => ['nullable', 'string', 'max:255'],
                    'position' => ['required', 'integer', 'between:0,65535'],
                    'download_failed_at' => ['nullable', 'date'],
                    'created_at' => ['required', 'date'], 'updated_at' => ['required', 'date'],
                ])->validate();
                $source = $image['kind'].'|'.$image['source_id'];
                if (isset($imageIds[$image['id']]) || isset($sources[$source])) {
                    $this->invalid('The archive contains duplicate images.');
                }
                $imageIds[$image['id']] = true;
                $sources[$source] = true;
                if ($image['id'] === $game['header_image_id'] && $image['kind'] !== 'cover') {
                    $headerFound = true;
                }
                foreach (self::IMAGE_PATHS as $field) {
                    if ($image[$field] !== null) {
                        $this->validateMediaPath($image[$field]);
                        $referencedFiles[$image[$field]] = true;
                    }
                }
            }
            if (! $headerFound) {
                $this->invalid('A selected header image does not belong to its game.');
            }
        }
        if (array_diff_key($referencedFiles, $manifest['files']) !== [] || array_diff_key($manifest['files'], $referencedFiles) !== []) {
            $this->invalid('The manifest does not match the referenced images.');
        }
        foreach ($manifest['files'] as $path => $file) {
            $this->validateMediaPath($path);
            Validator::make(['file' => $file], [
                'file' => ['required', 'array:size,sha256'],
                'file.size' => ['required', 'integer', 'between:1,16777216'],
                'file.sha256' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/D'],
            ])->validate();
        }
    }

    /**
     * @param  array<string, array{size: int, sha256: string}>  $files
     * @param  array<string, int>  $entries
     * @return array<string, string>
     */
    private function stageImages(ZipArchive $zip, array $files, array $entries, string $prefix): array
    {
        $paths = [];
        foreach ($files as $name => $file) {
            if ($file['size'] !== $entries[$name]) {
                $this->invalid('An image has an unexpected size.');
            }
            $path = $prefix.'/'.$file['sha256'].'.webp';
            $stream = $zip->getStream($name);
            if ($stream === false) {
                $this->invalid('An image could not be read from the ZIP.');
            }
            try {
                $bytes = @stream_get_contents($stream, $file['size'] + 1);
            } finally {
                fclose($stream);
            }
            $info = is_string($bytes) ? @getimagesizefromstring($bytes) : false;
            if (! is_string($bytes) || strlen($bytes) !== $file['size'] || ! hash_equals($file['sha256'], hash('sha256', $bytes))
                || ! $info || $info[2] !== IMAGETYPE_WEBP) {
                $this->invalid('An image is damaged or its checksum does not match.');
            }
            if (! Storage::disk('local')->put($path, $bytes)) {
                throw new RuntimeException('The imported images could not be saved.');
            }
            $paths[$name] = $path;
        }

        return $paths;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    private function exportDates(array $data, array $fields): array
    {
        foreach ($fields as $field) {
            if ($data[$field] !== null) {
                $data[$field] = Carbon::parse($data[$field], config('app.timezone'))->utc()->toISOString();
            }
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    private function importDates(array $data, array $fields): array
    {
        foreach ($fields as $field) {
            if ($data[$field] !== null) {
                $data[$field] = Carbon::parse($data[$field], config('app.timezone'))->setTimezone(config('app.timezone'));
            }
        }

        return $data;
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['archive' => $message]);
    }
}
