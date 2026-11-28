<?php

namespace App\Http\Requests\Subscription;

use App\Support\SubscriptionTierCache;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(SubscriptionTierCache $tiers): array
    {
        $slugs = array_values(array_filter(array_unique([
            ...array_keys(config('subscriptions.tiers', [])),
            ...$tiers->activeOrdered()->pluck('slug')->all(),
        ]), fn (string $slug): bool => $tiers->tierBySlug($slug)?->is_active !== false));

        return [
            'tier' => ['required', Rule::in($slugs)],
            'billing_period' => ['required', Rule::in(['monthly', 'yearly'])],
        ];
    }
}
