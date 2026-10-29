<?php

namespace App\Mcp\Tools;

use App\Enums\GameFormat;
use App\Enums\GameStatus;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;

class GameWriteSchema
{
    /** @return array<string, Type> */
    public static function fields(JsonSchema $schema, bool $creating): array
    {
        $fields = [
            'title' => $schema->string()->min(1)->max(255)->required($creating),
            'platform' => $schema->string()->min(1)->max(80)->required($creating),
            'format' => $schema->string()->enum(GameFormat::class)->required($creating),
            'status' => $schema->string()->enum(GameStatus::class)->description('Defaults to planned for a new game.'),
            'rating' => $schema->integer()->min(1)->max(10)->nullable()
                ->description('Personal rating, not the provider score. Null clears it.'),
            'duration_minutes' => $schema->integer()->min(0)->max(6000000)->nullable()
                ->description('Expected length in minutes. Null clears it; zero is a known value.'),
            'total_played_minutes' => $schema->integer()->min(0)->max(6000000)->nullable()
                ->description('Time actually played in minutes, independent of expected length.'),
            'summary' => $schema->string()->max(10000)->nullable(),
            'release_year' => $schema->integer()->min(1950)->max(2200)->nullable(),
            'developer' => $schema->string()->max(255)->nullable(),
            'publisher' => $schema->string()->max(255)->nullable(),
            'header_image_id' => $schema->integer()->min(1)->nullable()
                ->description('An artwork or screenshot ID belonging to this copy. Null restores the automatic header.'),
        ];
        if ($creating) {
            $fields['igdb_id'] = $schema->integer()->min(1)->nullable()
                ->description('Optional known IGDB ID. Fetches metadata and queues images using the existing import flow.');
        } else {
            $fields = ['id' => $schema->integer()->min(1)->required()->description('Exact library copy ID returned by search_games.')] + $fields;
        }

        return $fields;
    }
}
