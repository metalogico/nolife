<?php

namespace App\Models;

use App\Enums\GameFormat;
use App\Enums\GameStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable([
    'title',
    'platform',
    'format',
    'status',
    'rating',
    'duration_minutes',
    'total_played_minutes',
    'summary',
    'release_year',
    'developer',
    'publisher',
    'genres',
    'igdb_id',
    'igdb_url',
    'metadata_fetched_at',
    'header_image_id',
])]
class Game extends Model
{
    use HasFactory;

    protected $attributes = ['status' => 'planned', 'format' => 'physical'];

    protected static function booted(): void
    {
        static::saving(function (Game $game): void {
            $format = $game->format instanceof GameFormat ? $game->format->value : $game->format;
            $game->identity_key = self::identityKey($game->title, $game->platform, $format, $game->igdb_id);
        });
    }

    public static function identityKey(string $title, string $platform, string $format, ?int $igdbId): string
    {
        return hash('sha256', ($igdbId ? 'igdb:'.$igdbId : Str::lower(trim($title))).'|'.Str::lower(trim($platform)).'|'.$format);
    }

    protected function casts(): array
    {
        return [
            'format' => GameFormat::class,
            'status' => GameStatus::class,
            'rating' => 'integer',
            'duration_minutes' => 'integer',
            'total_played_minutes' => 'integer',
            'release_year' => 'integer',
            'igdb_id' => 'integer',
            'header_image_id' => 'integer',
            'genres' => 'array',
            'metadata_fetched_at' => 'datetime',
        ];
    }

    public function images(): HasMany
    {
        return $this->hasMany(GameImage::class)->orderBy('position')->orderBy('id');
    }
}
