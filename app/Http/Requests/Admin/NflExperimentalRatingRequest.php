<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class NflExperimentalRatingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->is_admin;
    }

    public function rules(): array
    {
        return ['season' => ['sometimes', 'integer', 'min:2000', 'max:2100']];
    }
}
