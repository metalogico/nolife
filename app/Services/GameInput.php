<?php

namespace App\Services;

use App\Enums\GameFormat;
use App\Enums\GameStatus;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class GameInput
{
    /** @return array<string, array<int, mixed>> */
    public static function rules(bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'title' => [$required, 'string', 'max:255'],
            'platform' => [$required, 'string', 'max:80'],
            'format' => [$required, Rule::enum(GameFormat::class)],
            'status' => ['sometimes', Rule::enum(GameStatus::class)],
            'header_image_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'rating' => ['sometimes', 'nullable', 'integer', 'between:1,10'],
            'duration_minutes' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:6000000'],
            'total_played_minutes' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:6000000'],
            'summary' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'release_year' => ['sometimes', 'nullable', 'integer', 'between:1950,2200'],
            'developer' => ['sometimes', 'nullable', 'string', 'max:255'],
            'publisher' => ['sometimes', 'nullable', 'string', 'max:255'],
            'igdb_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normalize(array $data): array
    {
        foreach ($data as $field => $value) {
            if (is_string($value)) {
                $trimmed = trim($value);
                $data[$field] = $trimmed === '' ? null : $trimmed;
            }
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function validate(array $data, bool $creating): array
    {
        $rules = self::rules($creating);
        if (! $creating) {
            unset($rules['igdb_id']);
        }
        $unknown = array_diff(array_keys($data), array_keys($rules));
        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'arguments' => 'Unsupported game fields: '.implode(', ', $unknown).'.',
            ]);
        }
        if (! $creating && $data === []) {
            throw ValidationException::withMessages(['arguments' => 'Provide at least one field to update.']);
        }

        return Validator::make(self::normalize($data), $rules)->validate();
    }
}
