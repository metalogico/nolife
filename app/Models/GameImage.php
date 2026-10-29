<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

#[Fillable(['kind', 'source_id', 'source_url', 'path', 'thumbnail_path', 'ambient_path', 'position', 'download_failed_at'])]
class GameImage extends Model
{
    use HasFactory;

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function url(string $variant = 'full'): ?string
    {
        $path = match ($variant) {
            'thumbnail' => $this->thumbnail_path,
            'ambient' => $this->ambient_path,
            default => $this->path,
        };

        if (! $path && $this->path) {
            $path = $this->path;
            $variant = 'full';
        }

        if ($path && Storage::disk('local')->exists($path)) {
            return route('media.show', ['image' => $this->id, 'variant' => $variant], false);
        }

        return $this->source_url;
    }
}
