<?php

namespace App\Http\Requests\Api\CBB;

use Illuminate\Foundation\Http\FormRequest;

class BracketStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'season' => 'required|integer|min:2000|max:2100',
            'name' => 'nullable|string|max:255',
            'group_id' => 'nullable|integer',
            'picks' => 'nullable|array',
            'picks.*' => 'string|max:255',
        ];
    }
}
