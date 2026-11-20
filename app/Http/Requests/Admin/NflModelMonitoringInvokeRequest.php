<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class NflModelMonitoringInvokeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'evidence_season' => ['nullable', 'integer', 'between:2000,2100'],
            'evidence_model' => ['nullable', 'string', 'max:128'],
            'evidence_week' => ['nullable', 'integer', 'between:2,22'],
        ];
    }
}
