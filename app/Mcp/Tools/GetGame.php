<?php

namespace App\Mcp\Tools;

use App\Http\Resources\GameResource;
use App\Models\Game;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_game')]
#[Description('Read the full details of one library copy by its exact ID, including personal values and local gallery image IDs.')]
#[IsReadOnly]
#[IsOpenWorld(false)]
class GetGame extends LibraryTool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        return $this->respond(function () use ($request): array {
            $data = $request->validate(['id' => ['required', 'integer', 'min:1']]);
            $game = Game::query()->with('images')->findOrFail($data['id']);

            return ['game' => (new GameResource($game))->resolve()];
        });
    }

    public function schema(JsonSchema $schema): array
    {
        return ['id' => $schema->integer()->min(1)->required()];
    }
}
