<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TierRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
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
            'permissions.*' => ['string'],
            'data_permissions' => ['nullable', 'array'],
            'data_permissions.*' => ['string', 'in:spread,win_probability,confidence_score,elo_diff,away_elo,home_elo,betting_value'],
            'predictions_limit' => ['nullable', 'integer', 'min:1'],
            'team_metrics_limit' => ['nullable', 'integer', 'min:1'],
            'is_default' => ['boolean'],
            'is_active' => ['boolean'],
            'sort_order' => ['integer', 'min:0'],
        ];
    }
}
