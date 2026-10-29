<?php

namespace Database\Factories;

use App\Enums\GameFormat;
use App\Enums\GameStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

class GameFactory extends Factory
{
    public function definition(): array
    {
        return [
            'title' => fake()->unique()->words(3, true),
            'platform' => 'PS5',
            'format' => GameFormat::Physical,
            'status' => GameStatus::Planned,
        ];
    }

    public function playing(): static
    {
        return $this->state(fn (): array => ['status' => GameStatus::Playing]);
    }
}
