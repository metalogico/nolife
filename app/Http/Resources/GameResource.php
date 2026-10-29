<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GameResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $images = $this->images;
        $cover = $images->firstWhere('kind', 'cover');
        $background = $images->firstWhere('id', $this->header_image_id)
            ?? $images->firstWhere('kind', 'artwork')
            ?? $images->firstWhere('kind', 'screenshot')
            ?? $cover;

        return [
            'id' => $this->id,
            'title' => $this->title,
            'platform' => $this->platform,
            'format' => $this->format->value,
            'status' => $this->status->value,
            'rating' => $this->rating,
            'duration_minutes' => $this->duration_minutes,
            'total_played_minutes' => $this->total_played_minutes,
            'summary' => $this->summary,
            'release_year' => $this->release_year,
            'developer' => $this->developer,
            'publisher' => $this->publisher,
            'genres' => $this->genres ?? [],
            'igdb_id' => $this->igdb_id,
            'igdb_url' => $this->igdb_url,
            'metadata_fetched_at' => $this->metadata_fetched_at?->toIso8601String(),
            'cover_url' => $cover?->url(),
            'thumbnail_url' => $cover?->url('thumbnail'),
            'background_url' => $background?->url(),
            'header_image_id' => $this->header_image_id,
            'ambient_url' => $background?->url('ambient'),
            'images_pending' => $images->whereNull('path')
                ->whereNull('download_failed_at')
                ->whereNotNull('source_url')
                ->isNotEmpty(),
            'images' => $images->map(fn ($image): array => [
                'id' => $image->id,
                'kind' => $image->kind,
                'url' => $image->url(),
                'thumbnail_url' => $image->url('thumbnail'),
            ])->values(),
        ];
    }
}
