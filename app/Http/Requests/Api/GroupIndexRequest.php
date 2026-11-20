<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class GroupIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => 'nullable|string|max:100',
            'sport' => 'nullable|string|max:50',
            'season' => 'nullable|integer|min:2000|max:2100',
        ];
    }
}
