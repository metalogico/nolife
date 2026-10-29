<?php

namespace App\Mcp\Tools;

use App\Enums\GameFormat;
use App\Enums\GameStatus;
use App\Services\GameSearch;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('search_games')]
#[Description(
    'Search or browse your library by partial title and filters. For top scores sort by rating; for longest '
    .'expected length sort by duration_minutes. Numeric rankings exclude unknown values and report their count.'
    .' Follow meta.next_page to retrieve every match; each platform/format copy is separate.'
)]
#[IsReadOnly]
#[IsOpenWorld(false)]
class SearchGames extends LibraryTool
{
    public function handle(Request $request, GameSearch $search): Response|ResponseFactory
    {
        return $this->respond(fn (): array => $search->search($request->all()));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->max(255)
                ->description('Optional partial title; Unicode case and straight/curly apostrophes are normalized for matching only.'),
            'status' => $schema->string()->enum(GameStatus::class),
            'platform' => $schema->string()->max(80),
            'format' => $schema->string()->enum(GameFormat::class),
            'genre' => $schema->string()->max(255)->description('Exact genre from get_library_summary.'),
            'sort_by' => $schema->string()->enum(GameSearch::SORTS)->default('title'),
            'sort_direction' => $schema->string()->enum(['asc', 'desc'])->description('Defaults to asc for title; desc for other fields.'),
            'page' => $schema->integer()->min(1)->default(1),
            'per_page' => $schema->integer()->min(1)->max(100)->default(25),
        ];
    }
}
