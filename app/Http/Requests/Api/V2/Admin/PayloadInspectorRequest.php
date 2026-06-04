<?php

namespace App\Http\Requests\Api\V2\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PayloadInspectorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];

        if ($this->has('sports')) {
            $normalized['sports'] = $this->normalizeSportsInput($this->input('sports'));
        }

        if ($this->has('include_payload')) {
            $normalized['include_payload'] = $this->normalizeBooleanInput($this->input('include_payload'));
        }

        if ($this->has('include_warnings')) {
            $normalized['include_warnings'] = $this->normalizeBooleanInput($this->input('include_warnings'));
        }

        $this->merge($normalized);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'profile' => ['required', 'string', Rule::in(['dashboard'])],
            'date' => ['sometimes', 'date_format:Y-m-d'],
            'sports' => ['sometimes', 'array'],
            'sports.*' => ['string', Rule::in(array_keys((array) config('sports.domains', [])))],
            'include_payload' => ['sometimes', 'boolean'],
            'include_warnings' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array{profile: string, date: string, sports: array<int, string>, include_payload: bool, include_warnings: bool}
     */
    public function inspectorInputs(): array
    {
        $safe = $this->safe();

        return [
            'profile' => (string) $safe->input('profile'),
            'date' => (string) $safe->input('date', now()->toDateString()),
            'sports' => (array) $safe->input('sports', array_keys((array) config('sports.domains', []))),
            'include_payload' => (bool) $safe->input('include_payload', false),
            'include_warnings' => (bool) $safe->input('include_warnings', false),
        ];
    }

    /**
     * @return array<int, string>|null
     */
    private function normalizeSportsInput(mixed $sports): ?array
    {
        if ($sports === null || $sports === '') {
            return null;
        }

        $values = is_array($sports) ? $sports : explode(',', (string) $sports);

        return collect($values)
            ->map(fn (mixed $sport): string => strtolower(trim((string) $sport)))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function normalizeBooleanInput(mixed $value): mixed
    {
        if (is_string($value)) {
            $normalized = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            return $normalized ?? $value;
        }

        return $value;
    }
}
