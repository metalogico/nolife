<?php

namespace App\Http\Requests;

use App\PersonalAccess;
use App\Services\GameInput;
use Illuminate\Foundation\Http\FormRequest;

class SaveGameRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(PersonalAccess::class)->authenticated($this);
    }

    public function rules(): array
    {
        return GameInput::rules($this->isMethod('POST'));
    }

    protected function prepareForValidation(): void
    {
        $this->merge(GameInput::normalize($this->all()));
    }
}
