<?php

namespace App\Mcp\Tools;

use App\Http\Resources\GameResource;
use App\Services\GameInput;
use App\Services\GameLibrary;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('add_game')]
#[Description(
    'Add one library copy with a title, platform and physical/digital format. Status defaults to planned. A '
    .'known igdb_id optionally imports metadata. Existing copies return a duplicate error; this never updates '
    .'them automatically. Requires a write token.'
)]
#[IsReadOnly(false)]
#[IsDestructive(false)]
#[IsIdempotent(false)]
#[IsOpenWorld]
class AddGame extends LibraryTool
{
    protected bool $writes = true;

    public function handle(Request $request, GameInput $input, GameLibrary $library): Response|ResponseFactory
    {
        return $this->respond(function () use ($request, $input, $library): array {
            $game = $library->save(null, $input->validate($request->all(), true));

            return ['game' => (new GameResource($game))->resolve()];
        });
    }

    public function schema(JsonSchema $schema): array
    {
        return GameWriteSchema::fields($schema, true);
    }
}
