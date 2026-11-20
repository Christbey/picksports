<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

class AdminSettingsAddUserToGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'group_id' => ['required', 'integer'],
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ];
    }
}
