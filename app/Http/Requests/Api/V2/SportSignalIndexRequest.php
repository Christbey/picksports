<?php

namespace App\Http\Requests\Api\V2;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

class SportSignalIndexRequest extends FormRequest
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
            'season' => ['sometimes', 'integer', 'min:2000', 'max:2100'],
            'as_of_date' => ['sometimes', 'date'],
        ];
    }

    /**
     * @return array{season?: int, as_of_date?: Carbon}
     */
    public function validatedFilters(): array
    {
        $filters = $this->safe()->only(['season', 'as_of_date']);

        if (array_key_exists('season', $filters)) {
            $filters['season'] = (int) $filters['season'];
        }

        if (array_key_exists('as_of_date', $filters)) {
            $filters['as_of_date'] = Carbon::parse((string) $filters['as_of_date']);
        }

        return $filters;
    }
}
