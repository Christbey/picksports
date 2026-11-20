<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

class OddsApiPlayerMappingUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'espn_player_name' => 'nullable|string|max:255',
            'espn_player_id' => 'nullable|integer|min:1',
        ];
    }
}
