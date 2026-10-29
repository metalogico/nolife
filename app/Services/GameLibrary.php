<?php

namespace App\Services;

use App\Jobs\DownloadGameImage;
use App\Models\Game;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class GameLibrary
{
    public function __construct(private IgdbClient $igdb) {}

    public function save(?Game $game, array $data): Game
    {
        $metadata = null;
        if (! $game && ! empty($data['igdb_id'])) {
            $metadata = $this->igdb->game((int) $data['igdb_id']);
            $data = array_merge($data, $this->metadataAttributes($metadata));
        } else {
            unset($data['igdb_id']);
        }

        return $this->persist($game, $data, $metadata);
    }

    public function match(Game $game, int $igdbId): Game
    {
        $metadata = $this->igdb->game($igdbId);

        return $this->persist($game, $this->metadataAttributes($metadata), $metadata);
    }

    private function metadataAttributes(array $metadata): array
    {
        return [
            'title' => $metadata['title'],
            'summary' => $metadata['summary'] ?: null,
            'release_year' => $metadata['release_year'],
            'developer' => $metadata['developer'],
            'publisher' => $metadata['publisher'],
            'genres' => $metadata['genres'],
            'igdb_id' => $metadata['id'],
            'igdb_url' => $metadata['url'],
            'metadata_fetched_at' => now(),
        ];
    }

    private function persist(?Game $game, array $data, ?array $metadata): Game
    {
        return app(LibraryLock::class)->run(fn (): Game => $this->persistLocked($game, $data, $metadata));
    }

    private function persistLocked(?Game $game, array $data, ?array $metadata): Game
    {
        try {
            return DB::transaction(function () use ($game, $data, $metadata): Game {
                $this->validateHeaderImage($game, $data);

                $game ??= new Game;
                $game->fill($data);
                $game->save();
                if ($metadata) {
                    $this->syncImages($game, $metadata['images']);
                }

                return $game->refresh()->load('images');
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'title' => 'This game is already in your collection on this platform and format.',
            ]);
        }
    }

    private function validateHeaderImage(?Game $game, array $data): void
    {
        if (! isset($data['header_image_id'])) {
            return;
        }

        $belongsToGallery = $game && $game->images()
            ->whereKey($data['header_image_id'])
            ->whereIn('kind', ['artwork', 'screenshot'])
            ->exists();

        if (! $belongsToGallery) {
            throw ValidationException::withMessages([
                'header_image_id' => 'Choose a gallery image from this game.',
            ]);
        }
    }

    private function syncImages(Game $game, array $images): void
    {
        $retainedImageIds = [];
        foreach ($images as $position => $image) {
            $record = $game->images()->firstOrCreate(
                ['kind' => $image['kind'], 'source_id' => $image['source_id']],
                ['source_url' => $image['url'], 'position' => $position],
            );
            $record->update(['position' => $position, 'source_url' => $image['url']]);
            $retainedImageIds[] = $record->id;
            if (! $record->path) {
                $record->update(['download_failed_at' => null]);
                DownloadGameImage::dispatch($record->id)->afterCommit();
            }
        }

        $obsolete = $game->images()->whereNotIn('id', $retainedImageIds)->get();
        foreach ($obsolete as $record) {
            $paths = array_filter([$record->path, $record->thumbnail_path, $record->ambient_path]);
            $record->delete();
            DB::afterCommit(fn () => Storage::disk('local')->delete($paths));
        }
    }

    public function remove(Game $game): void
    {
        app(LibraryLock::class)->run(fn () => $this->removeLocked($game));
    }

    private function removeLocked(Game $game): void
    {
        $paths = $game->images()->get()
            ->flatMap(fn ($image): array => array_filter([
                $image->path,
                $image->thumbnail_path,
                $image->ambient_path,
            ]))
            ->all();
        DB::transaction(fn () => $game->delete());
        Storage::disk('local')->delete($paths);
    }
}
