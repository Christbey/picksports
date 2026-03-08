<?php

namespace App\Http\Requests\Api\Auth;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:120'],
        ];
    }

    public function deviceName(): string
    {
        $deviceName = trim($this->string('device_name')->toString());

        if ($deviceName !== '') {
            return $deviceName;
        }

        $userAgent = trim((string) $this->userAgent());

        if ($userAgent !== '') {
            return mb_substr($userAgent, 0, 120);
        }

        return 'api-client';
    }
}
