<?php

namespace App\Http\Requests\Api\V2;

use App\Services\TeamStats\CompletedTeamStatScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SportTeamSeasonAverageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['season' => ['sometimes', 'integer', 'between:1,9999'],
            'season_type' => ['sometimes', 'string', Rule::in(CompletedTeamStatScope::phases((string) $this->route('sport')))]];
    }

    public function validatedFilters(): array
    {
        $filters = $this->safe()->only(['season', 'season_type']);
        if (array_key_exists('season', $filters)) {
            $filters['season'] = (int) $filters['season'];
        }

        return $filters;
    }
}
