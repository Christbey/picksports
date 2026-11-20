<?php

namespace App\Http\Requests\Api\V2;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class NativeDeviceSessionStorePushRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'provider' => ['required', Rule::in(['apns', 'fcm'])],
            'device_token' => ['required', 'string', 'max:4096'],
            'environment' => ['nullable', Rule::in(['sandbox', 'production'])],
        ];
    }
}
