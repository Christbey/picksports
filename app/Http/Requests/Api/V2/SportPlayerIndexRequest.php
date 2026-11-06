<?php

namespace App\Http\Requests\Api\V2;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SportPlayerIndexRequest extends FormRequest
{
    private const MAX_PER_PAGE = 100;

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
            'team_id' => [Rule::requiredIf(fn (): bool => $this->route('sport') === 'cbb' && $this->has('season') && ! $this->route('team')), 'integer', 'min:1'],
            ...($this->route('sport') === 'cbb' ? ['season' => ['sometimes', 'integer', 'min:2000', 'max:2100']] : []),
            'position' => ['sometimes', 'string', 'max:50'],
            'status' => ['sometimes', 'string', 'max:100'],
            'search' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
        ];
    }

    /**
     * @return array{team_id?: int, position?: string, status?: string, search?: string, per_page?: int}
     */
    public function validatedFilters(): array
    {
        $filters = $this->safe()->only([
            'team_id',
            'season',
            'position',
            'status',
            'search',
            'per_page',
        ]);

        foreach (['position', 'status', 'search'] as $key) {
            if (array_key_exists($key, $filters)) {
                $filters[$key] = trim((string) $filters[$key]);
            }
        }

        foreach (['team_id', 'season', 'per_page'] as $key) {
            if (array_key_exists($key, $filters)) {
                $filters[$key] = (int) $filters[$key];
            }
        }

        if (array_key_exists('per_page', $filters)) {
            $filters['per_page'] = min((int) $filters['per_page'], self::MAX_PER_PAGE);
        }

        return array_filter($filters, fn (mixed $value): bool => $value !== '');
    }
}
