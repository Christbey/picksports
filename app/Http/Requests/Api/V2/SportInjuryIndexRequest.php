<?php

namespace App\Http\Requests\Api\V2;

use Illuminate\Foundation\Http\FormRequest;

class SportInjuryIndexRequest extends FormRequest
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
            'active' => ['sometimes', 'boolean'],
            'actionable' => ['sometimes', 'boolean'],
            'team_id' => ['sometimes', 'integer', 'min:1'],
            'status' => ['sometimes', 'string', 'max:100'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:500'],
        ];
    }

    /**
     * @return array{active?: bool, team_id?: int, status?: string}
     */
    public function validatedFilters(): array
    {
        $filters = $this->safe()->only(['active', 'actionable', 'team_id', 'status', 'limit']);

        foreach (['active', 'actionable'] as $booleanFilter) {
            if (array_key_exists($booleanFilter, $filters)) {
                $filters[$booleanFilter] = filter_var($filters[$booleanFilter], FILTER_VALIDATE_BOOL);
            }
        }

        if (array_key_exists('team_id', $filters)) {
            $filters['team_id'] = (int) $filters['team_id'];
        }

        if (array_key_exists('status', $filters)) {
            $filters['status'] = trim((string) $filters['status']);
        }

        if (array_key_exists('limit', $filters)) {
            $filters['limit'] = (int) $filters['limit'];
        }

        return array_filter(
            $filters,
            fn (mixed $value): bool => $value !== null && $value !== ''
        );
    }
}
