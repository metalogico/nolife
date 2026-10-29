<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use RuntimeException;

class GameImageFiles
{
    public function store(string $body, string $basePath, bool $cover): array
    {
        $source = @imagecreatefromstring($body);
        if (! $source) {
            throw new RuntimeException('The image could not be decoded.');
        }
        try {
            return [
                'path' => $this->encode($source, $basePath.'.webp', $cover ? 720 : 1600, 86),
                'thumbnail_path' => $this->encode($source, $basePath.'-thumb.webp', 400, 82),
                'ambient_path' => $this->encode($source, $basePath.'-ambient.webp', 64, 60),
            ];
        } finally {
            imagedestroy($source);
        }
    }

    private function encode(\GdImage $source, string $path, int $maxWidth, int $quality): string
    {
        $ratio = min(1, $maxWidth / imagesx($source));
        $width = max(1, (int) round(imagesx($source) * $ratio));
        $height = max(1, (int) round(imagesy($source) * $ratio));
        $scaled = imagescale($source, $width, $height, IMG_BICUBIC);
        if (! $scaled) {
            throw new RuntimeException('Image resizing failed.');
        }
        ob_start();
        imagewebp($scaled, null, $quality);
        $bytes = ob_get_clean();
        imagedestroy($scaled);
        if (! is_string($bytes) || ! Storage::disk('local')->put($path, $bytes)) {
            throw new RuntimeException('Image storage failed.');
        }

        return $path;
    }
}
