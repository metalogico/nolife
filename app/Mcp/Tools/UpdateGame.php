<?php

namespace App\Mcp\Tools;

use App\Http\Resources\GameResource;
use App\Models\Game;
use App\Services\GameInput;
use App\Services\GameLibrary;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('update_game')]
#[Description(
    'Update only supplied fields on one exact library copy ID. Omitted fields are preserved; null clears '
    .'nullable fields. Read the copy first when its identity is ambiguous. Cannot change IGDB associations or '
    .'upload images. Requires a write token.'
)]
#[IsReadOnly(false)]
#[IsDestructive]
#[IsOpenWorld(false)]
class UpdateGame extends LibraryTool
{
    protected bool $writes = true;

    public function handle(Request $request, GameInput $input, GameLibrary $library): Response|ResponseFactory
    {
        return $this->respond(function () use ($request, $input, $library): array {
            $identity = $request->validate(['id' => ['required', 'integer', 'min:1']]);
            $data = $request->all();
            unset($data['id']);
            $changes = $input->validate($data, false);
            $game = $library->save(Game::query()->findOrFail($identity['id']), $changes);

            return ['game' => (new GameResource($game))->resolve()];
        });
    }

    public function schema(JsonSchema $schema): array
    {
        return GameWriteSchema::fields($schema, false);
    }
}
