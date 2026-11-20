<?php

namespace App\Http\Requests;

use App\Enums\PredictionSport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BetTrackerIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'prediction_id' => ['nullable', 'regex:/^(?:[1-9]\d*|[0-9A-HJKMNP-TV-Z]{26})$/i', 'required_with:prediction_sport'],
            'prediction_sport' => ['nullable', Rule::enum(PredictionSport::class), 'required_with:prediction_id'],
            'prediction_type' => ['prohibited'],
        ];
    }
}
