<?php

namespace App\Http\Requests;

use App\Enums\GameFormat;
use App\Enums\GameStatus;
use App\PersonalAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveGameRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(PersonalAccess::class)->authenticated($this);
    }

    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

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

    protected function prepareForValidation(): void
    {
        foreach (['title', 'platform'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => trim($this->input($field))]);
            }
        }
    }
}
