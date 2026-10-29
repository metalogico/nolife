<?php

namespace App\Http\Controllers;

use App\Enums\GameFormat;
use App\Enums\GameStatus;
use App\Http\Requests\SaveGameRequest;
use App\Http\Resources\GameResource;
use App\Models\Game;
use App\Services\GameLibrary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class GameController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::enum(GameStatus::class)],
            'platform' => ['nullable', 'string', 'max:80'],
            'format' => ['nullable', Rule::enum(GameFormat::class)],
            'genre' => ['nullable', 'string', 'max:255'],
            'sort' => ['nullable', Rule::in(['title', 'recent', 'rating'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $query = Game::query()->with('images');
        if (filled($filters['q'] ?? null)) {
            $search = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($filters['q']));
            $query->whereRaw("title LIKE ? ESCAPE '\\' COLLATE NOCASE", ['%'.$search.'%']);
        }
        foreach (['status', 'platform', 'format'] as $filter) {
            if (filled($filters[$filter] ?? null)) {
                $query->where($filter, $filters[$filter]);
            }
        }
        if (filled($filters['genre'] ?? null)) {
            $query->whereJsonContains('genres', $filters['genre']);
        }
        match ($filters['sort'] ?? 'title') {
            'recent' => $query->orderByDesc('created_at'),
            'rating' => $query->orderByDesc('rating'),
            default => $query->orderByRaw('title COLLATE NOCASE'),
        };

        return GameResource::collection($query->orderBy('id')->paginate(36)->withQueryString());
    }

    public function library(GameLibrary $library): JsonResponse
    {
        return response()->json($library->overview());
    }

    public function store(SaveGameRequest $request, GameLibrary $library): GameResource
    {
        return new GameResource($library->save(null, $request->validated()));
    }

    public function show(Game $game): GameResource
    {
        return new GameResource($game->load('images'));
    }

    public function update(SaveGameRequest $request, Game $game, GameLibrary $library): GameResource
    {
        return new GameResource($library->save($game, $request->validated()));
    }

    public function destroy(Game $game, GameLibrary $library): Response
    {
        $library->remove($game);

        return response()->noContent();
    }
}
