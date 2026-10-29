<?php

namespace App\Http\Controllers;

use App\Http\Resources\GameResource;
use App\Models\Game;
use App\Services\GameLibrary;
use App\Services\IgdbClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class IgdbController extends Controller
{
    public function index(Request $request, IgdbClient $igdb): JsonResponse
    {
        $data = $request->validate([
            'query' => ['required', 'string', 'min:1', 'max:200'],
            'by' => ['sometimes', Rule::in(['title', 'id'])],
        ]);
        if (($data['by'] ?? 'title') === 'id') {
            $request->validate(['query' => ['required', 'integer', 'min:1']]);
        }

        return response()->json(['data' => $igdb->search($data['query'], $data['by'] ?? 'title')]);
    }

    public function show(int $igdbId, IgdbClient $igdb): JsonResponse
    {
        return response()->json(['data' => $igdb->game($igdbId)]);
    }

    public function store(Request $request, Game $game, GameLibrary $library): GameResource
    {
        $data = $request->validate(['igdb_id' => ['required', 'integer', 'min:1']]);

        return new GameResource($library->match($game, $data['igdb_id']));
    }
}
