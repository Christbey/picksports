<?php

namespace App\Http\Requests;

use App\Support\Validation\AlertPreferenceRules;
use Illuminate\Foundation\Http\FormRequest;

class AlertPreferenceStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return AlertPreferenceRules::apiStore();
    }
}
