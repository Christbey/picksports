<?php

namespace App\Contracts\Predictions;

interface CanonicalReleaseDefinition
{
    public function sport(): string;

    public function calculatorName(): string;

    public function inputSchemaVersion(): string;

    public function semanticVersion(): string;

    /** @return array<string, mixed> */
    public function configuration(): array;
}
