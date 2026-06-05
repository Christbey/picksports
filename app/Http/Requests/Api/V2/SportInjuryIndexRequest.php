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
            'team_id' => ['sometimes', 'integer', 'min:1'],
            'status' => ['sometimes', 'string', 'max:100'],
        ];
    }

    /**
     * @return array{active?: bool, team_id?: int, status?: string}
     */
    public function validatedFilters(): array
    {
        $filters = $this->safe()->only(['active', 'team_id', 'status']);

        if (array_key_exists('active', $filters)) {
            $filters['active'] = filter_var($filters['active'], FILTER_VALIDATE_BOOL);
        }

        if (array_key_exists('team_id', $filters)) {
            $filters['team_id'] = (int) $filters['team_id'];
        }

        if (array_key_exists('status', $filters)) {
            $filters['status'] = trim((string) $filters['status']);
        }

        return array_filter(
            $filters,
            fn (mixed $value): bool => $value !== null && $value !== ''
        );
    }
}
