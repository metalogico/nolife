<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class McpSettingFactory extends Factory
{
    public function definition(): array
    {
        return [
            'id' => 1,
            'enabled' => false,
            'read_token_hash' => null,
            'write_token_hash' => null,
        ];
    }
}
