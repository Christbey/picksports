<?php

namespace App\Http\Requests\Api\V2;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SportDepthChartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'season' => ['sometimes', 'integer', 'min:1', 'max:9999'],
            'season_type' => ['sometimes', 'string', Rule::in(array_keys($this->phaseAliases()))],
            'before_date' => ['sometimes', 'date'],
        ];
    }

    /** @return array{season?:int,season_type?:string,before_date?:string} */
    public function validatedFilters(): array
    {
        $filters = $this->validated();
        if (isset($filters['season'])) {
            $filters['season'] = (int) $filters['season'];
        }
        if (isset($filters['season_type'])) {
            $filters['season_type'] = $this->phaseAliases()[$filters['season_type']];
        }

        return $filters;
    }

    /** Preserve the configured codes/names and the depth reader's existing named aliases. */
    private function phaseAliases(): array
    {
        $sport = strtolower((string) $this->route('sport'));
        $types = (array) config("{$sport}.season.types", []);
        $names = (array) config("{$sport}.season.type_names", []);
        $legacy = [1 => ['Preseason', 'Pre Season'], 2 => ['Regular Season', 'Regular'], 3 => ['Postseason', 'Post Season', 'Playoffs']];
        $aliases = [];
        foreach ($types as $key => $code) {
            foreach ([$key, (string) $code, ...($legacy[$code] ?? []), ...(isset($names[$key]) ? [$names[$key]] : [])] as $alias) {
                $aliases[(string) $alias] = (string) $code;
            }
        }

        return $aliases;
    }
}
