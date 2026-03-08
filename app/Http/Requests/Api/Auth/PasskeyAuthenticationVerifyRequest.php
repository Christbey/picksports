<?php

namespace App\Http\Requests\Api\Auth;

use Illuminate\Foundation\Http\FormRequest;

class PasskeyAuthenticationVerifyRequest extends FormRequest
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
            'challenge_id' => ['required', 'string', 'max:255'],
            'credential_id' => ['required', 'string', 'max:512'],
            'client_data_json' => ['required', 'string', 'max:4096'],
            'authenticator_data' => ['required', 'string', 'max:4096'],
            'signature' => ['required', 'string', 'max:4096'],
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

        return 'api-passkey-client';
    }
}
