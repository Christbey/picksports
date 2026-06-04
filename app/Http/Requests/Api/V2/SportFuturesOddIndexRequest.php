<?php

namespace App\Http\Requests\Api\V2;

use Illuminate\Foundation\Http\FormRequest;

class SportFuturesOddIndexRequest extends FormRequest
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
            'season' => ['sometimes', 'integer'],
            'market_key' => ['sometimes', 'string', 'max:100'],
            'bookmaker' => ['sometimes', 'string', 'max:100'],
            'team_id' => ['sometimes', 'integer', 'min:1'],
            'player_id' => ['sometimes', 'integer', 'min:1'],
            'event_id' => ['sometimes', 'string', 'max:150'],
            'outcome_name' => ['sometimes', 'string', 'max:150'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function validatedFilters(): array
    {
        $filters = $this->safe()->only([
            'season',
            'market_key',
            'bookmaker',
            'team_id',
            'player_id',
            'event_id',
            'outcome_name',
            'per_page',
        ]);

        foreach (['season', 'team_id', 'player_id', 'per_page'] as $key) {
            if (array_key_exists($key, $filters)) {
                $filters[$key] = (int) $filters[$key];
            }
        }

        foreach (['market_key', 'bookmaker', 'event_id', 'outcome_name'] as $key) {
            if (array_key_exists($key, $filters)) {
                $filters[$key] = trim((string) $filters[$key]);
            }
        }

        if (array_key_exists('per_page', $filters)) {
            $filters['per_page'] = min((int) $filters['per_page'], self::MAX_PER_PAGE);
        }

        return array_filter($filters, fn (mixed $value): bool => $value !== '');
    }
}
