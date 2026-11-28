<?php

namespace App\Http\Requests\Settings;

use App\Services\WebPush\PushEndpointPolicy;
use Illuminate\Foundation\Http\FormRequest;

class WebPushSubscriptionStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'endpoint' => ['required', 'url', 'max:255', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! is_string($value)) {
                    return;
                }
                try {
                    app(PushEndpointPolicy::class)->host($value);
                } catch (\InvalidArgumentException) {
                    $fail('Use a supported browser push provider HTTPS endpoint (maximum 255 characters).');
                }
            }],
            'contentEncoding' => ['nullable', 'string', 'max:32'],
            'keys' => ['required', 'array'],
            'keys.p256dh' => ['required', 'string', 'max:1024'],
            'keys.auth' => ['required', 'string', 'max:256'],
        ];
    }
}
