<?php

namespace App\Http\Requests\Api\V2;

use Illuminate\Foundation\Http\FormRequest;

class SportGameIndexRequest extends FormRequest
{
    private const MAX_PER_PAGE = 500;

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
            'status' => ['sometimes', 'string', 'max:100'],
            'season' => ['sometimes', 'integer'],
            'from_date' => ['sometimes', 'date'],
            'to_date' => array_filter([
                'sometimes',
                'date',
                $this->filled('from_date') ? 'after_or_equal:from_date' : null,
            ]),
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
        ];
    }

    /**
     * @return array{status?: string, season?: int, from_date?: string, to_date?: string, per_page?: int}
     */
    public function validatedFilters(): array
    {
        $filters = $this->safe()->only([
            'status',
            'season',
            'from_date',
            'to_date',
            'per_page',
        ]);

        if (array_key_exists('status', $filters)) {
            $filters['status'] = (string) $filters['status'];
        }

        if (array_key_exists('season', $filters)) {
            $filters['season'] = (int) $filters['season'];
        }

        if (array_key_exists('from_date', $filters)) {
            $filters['from_date'] = (string) $filters['from_date'];
        }

        if (array_key_exists('to_date', $filters)) {
            $filters['to_date'] = (string) $filters['to_date'];
        }

        if (array_key_exists('per_page', $filters)) {
            $filters['per_page'] = min((int) $filters['per_page'], self::MAX_PER_PAGE);
        }

        return $filters;
    }
}
