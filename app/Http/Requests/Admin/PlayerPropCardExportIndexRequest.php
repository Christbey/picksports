<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class PlayerPropCardExportIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'sport' => ['nullable', 'in:NBA,NFL,MLB,CBB'],
            'date' => ['nullable', 'date'],
            'game' => ['nullable', 'integer'],
            'market' => ['nullable', 'string', 'max:100'],
            'tab' => ['nullable', 'in:props,predictions,futures,tournament'],
            'season' => ['nullable', 'integer', 'min:2000', 'max:2100'],
        ];
    }
}
