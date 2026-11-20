<?php

namespace App\Http\Requests\Api\CBB;

use Illuminate\Foundation\Http\FormRequest;

class BracketLeaderboardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'season' => 'required|integer|min:2000|max:2100',
            'group_id' => 'nullable|integer',
            'limit' => 'nullable|integer|min:1|max:100',
        ];
    }
}
