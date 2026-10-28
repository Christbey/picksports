<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class NflModelMonitoringRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['artifact' => ['nullable', 'uuid'], 'signals' => ['nullable', 'boolean'],
            'evidence_season' => ['nullable', 'integer', 'between:2000,2100'],
            'evidence_model' => ['nullable', 'string', 'max:128'], 'evidence_week' => ['nullable', 'integer', 'between:2,22']];
    }

    public function messages(): array
    {
        return ['artifact.uuid' => 'Choose a valid model artifact.', 'evidence_season.between' => 'Choose a valid season.',
            'evidence_model.max' => 'The model version is too long.', 'evidence_week.between' => 'Choose a week between 2 and 22.'];
    }
}
