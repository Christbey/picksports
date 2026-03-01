<?php

namespace App\Actions\Validation;

use App\Actions\Validation\Checks\GameCoverageCheck;
use App\Actions\Validation\Checks\TeamStatCoverageCheck;
use App\Actions\Validation\Contracts\ValidationCheck;

class SportValidator
{
    /**
     * @var array<int, ValidationCheck>
     */
    private array $checks;

    public function __construct()
    {
        $this->checks = [
            new GameCoverageCheck(),
            new TeamStatCoverageCheck(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function validate(string $sport): array
    {
        $profile = config("validation.sports.{$sport}");

        if (! is_array($profile)) {
            return [];
        }

        $results = [];

        foreach ($this->checks as $check) {
            $result = $check->run($sport, $profile);
            if ($result !== null) {
                $results[] = $result;
            }
        }

        return $results;
    }
}
