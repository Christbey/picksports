<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BettingRecommendationsRenderPlayerPropsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date' => ['nullable', 'date'],
            'view' => ['nullable', 'in:recommendations,all'],
            'game' => ['nullable', 'integer'],
            'market' => ['nullable', 'string', 'max:100'],
        ];
    }
}
