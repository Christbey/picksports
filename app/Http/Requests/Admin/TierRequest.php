<?php

namespace App\Http\Requests\Admin;

use App\Models\SubscriptionTier;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

class TierRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return (bool) $this->user()?->isAdmin();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $tierId = $this->route('tier');

        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'required',
                'string',
                'max:255',
                Rule::unique('subscription_tiers', 'slug')->ignore($tierId),
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $tier = $this->route('tier');
                    if ($tier instanceof SubscriptionTier && $value !== $tier->slug) {
                        $fail('Tier identifiers cannot be renamed. Change the display name, or create a new tier and migrate users explicitly.');
                    }
                    if (in_array(strtolower(trim((string) $value)), ['admin', 'super-admin', 'super_admin', strtolower((string) config('founding_users.role', 'founding_user'))], true)) {
                        $fail('This identifier is reserved for an independent access role.');
                    }
                    if (! $tier && (preg_match('/\A[a-z0-9]+(?:[-_][a-z0-9]+)*\z/', (string) $value) !== 1
                        || Role::query()->where('guard_name', config('auth.defaults.guard', 'web'))->whereRaw('LOWER(name) = ?', [strtolower((string) $value)])->exists())) {
                        $fail('Choose a lowercase tier identifier that does not already belong to an independent role.');
                    }
                },
            ],
            'description' => ['nullable', 'string'],
            'price_monthly' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'price_yearly' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'stripe_price_id_monthly' => ['nullable', 'string', 'max:255'],
            'stripe_price_id_yearly' => ['nullable', 'string', 'max:255'],
            'features' => ['nullable', 'array'],
            'features.predictions_per_day' => ['nullable', 'integer', 'min:0'],
            'features.historical_data_days' => ['nullable', 'integer', 'min:0'],
            'features.sports_access' => ['nullable', 'array'],
            'features.sports_access.*' => ['string'],
            'features.export_predictions' => ['boolean'],
            'features.api_access' => ['boolean'],
            'features.advanced_analytics' => ['boolean'],
            'features.email_alerts' => ['boolean'],
            'features.priority_support' => ['boolean'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
            'predictions_limit' => ['nullable', 'integer', 'min:1'],
            'team_metrics_limit' => ['nullable', 'integer', 'min:1'],
            'is_default' => ['boolean'],
            'is_active' => ['boolean'],
            'sort_order' => ['integer', 'min:0'],
        ];
    }
}
