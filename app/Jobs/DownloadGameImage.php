<?php

namespace App\Jobs;

use App\Models\GameImage;
use App\Services\GameImageFiles;
use App\Services\LibraryLock;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class DownloadGameImage implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 25;

    public array $backoff = [2, 10, 30];

    public function __construct(public int $imageId) {}

    public function handle(): void
    {
        $image = GameImage::find($this->imageId);
        if (! $image || $image->path || ! $image->source_url) {
            return;
        }
        $parts = parse_url($image->source_url);
        if (($parts['scheme'] ?? null) !== 'https' || ($parts['host'] ?? null) !== 'images.igdb.com') {
            throw new RuntimeException('Unsupported image source.');
        }

        $response = Http::connectTimeout(3)->timeout(12)->withOptions(['allow_redirects' => false])->get($image->source_url);
        if (! $response->successful()) {
            throw new RuntimeException('The image provider is temporarily unavailable.');
        }
        $body = $response->body();
        $info = @getimagesizefromstring($body);
        if (strlen($body) > 12 * 1024 * 1024 || ! $info || $info[0] > 5000 || $info[1] > 5000) {
            throw new RuntimeException('The provider image could not be processed.');
        }

        app(LibraryLock::class)->run(function () use ($image, $body): void {
            if (! GameImage::whereKey($image->id)->exists()) {
                return;
            }
            $base = 'media/'.$image->id.'/'.hash('sha256', $body);
            $paths = app(GameImageFiles::class)->store($body, $base, $image->kind === 'cover');
            if ($fresh = GameImage::find($this->imageId)) {
                $fresh->update([...$paths, 'download_failed_at' => null]);
            } else {
                Storage::disk('local')->delete(array_values($paths));
            }
        });
    }

    public function failed(?Throwable $exception): void
    {
        app(LibraryLock::class)->run(fn () => GameImage::whereKey($this->imageId)->update(['download_failed_at' => now()]));
    }
}
