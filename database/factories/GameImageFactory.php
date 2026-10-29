<?php

namespace Database\Factories;

use App\Models\Game;
use Illuminate\Database\Eloquent\Factories\Factory;

class GameImageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'game_id' => Game::factory(),
            'kind' => 'cover',
            'source_id' => 'co'.fake()->unique()->numerify('######'),
        ];
    }
}
