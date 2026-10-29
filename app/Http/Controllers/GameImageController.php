<?php

namespace App\Http\Controllers;

use App\Http\Resources\GameResource;
use App\Models\Game;
use App\Models\GameImage;
use App\Services\GameImageFiles;
use App\Services\LibraryLock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class GameImageController extends Controller
{
    public function show(GameImage $image, string $variant = 'full'): BinaryFileResponse
    {
        $path = match ($variant) {
            'thumbnail' => $image->thumbnail_path,
            'ambient' => $image->ambient_path,
            default => $image->path,
        };
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return response()->file(Storage::disk('local')->path($path), [
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function store(Request $request, Game $game): GameResource
    {
        $data = $request->validate([
            'cover' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192', 'dimensions:max_width=5000,max_height=5000'],
        ]);
        $file = $data['cover'];

        return app(LibraryLock::class)->run(function () use ($file, $game): GameResource {
            $paths = app(GameImageFiles::class)->store(
                file_get_contents($file->getRealPath()),
                'media/uploads/'.Str::uuid(),
                true,
            );
            $previousCovers = $game->images()->where('kind', 'cover')->get();
            DB::transaction(function () use ($game, $previousCovers, $paths): void {
                foreach ($previousCovers as $image) {
                    $image->delete();
                }
                $game->images()->create(['kind' => 'cover', 'source_id' => 'upload', ...$paths]);
            });
            foreach ($previousCovers as $image) {
                Storage::disk('local')->delete(array_filter([$image->path, $image->thumbnail_path, $image->ambient_path]));
            }

            return new GameResource($game->load('images'));
        });
    }
}
