<?php

namespace App\Mcp\Tools;

use App\Enums\GameFormat;
use App\Enums\GameStatus;
use App\Services\GameLibrary;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_library_summary')]
#[Description(
    'Read collection totals, counts by status and available platforms and genres. Also lists the accepted '
    .'status and format codes.'
)]
#[IsReadOnly]
#[IsOpenWorld(false)]
class GetLibrarySummary extends LibraryTool
{
    public function handle(GameLibrary $library): Response|ResponseFactory
    {
        return $this->respond(fn (): array => [
            ...$library->overview(),
            'statuses' => array_column(GameStatus::cases(), 'value'),
            'formats' => array_column(GameFormat::cases(), 'value'),
        ]);
    }
}
