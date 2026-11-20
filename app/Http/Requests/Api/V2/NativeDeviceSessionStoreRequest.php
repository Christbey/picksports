<?php

namespace App\Http\Requests\Api\V2;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class NativeDeviceSessionStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'device_name' => ['required', 'string', 'max:120'],
            'platform' => ['required', Rule::in(['ios', 'android'])],
            'device_identifier' => ['nullable', 'string', 'max:255'],
        ];
    }
}
