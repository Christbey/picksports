<?php

namespace App\Http\Requests\Settings;

use App\Support\Validation\AlertPreferenceRules;
use Illuminate\Foundation\Http\FormRequest;

class AlertPreferenceUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return AlertPreferenceRules::settingsUpdate();
    }
}
