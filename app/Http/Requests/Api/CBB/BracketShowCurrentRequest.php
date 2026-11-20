<?php

namespace App\Http\Requests\Api\CBB;

use Illuminate\Foundation\Http\FormRequest;

class BracketShowCurrentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'season' => 'required|integer|min:2000|max:2100',
        ];
    }
}
