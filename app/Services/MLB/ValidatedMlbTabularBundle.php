<?php

namespace App\Services\MLB;

/** The issuing inspector retains opaque object identity; constructing or cloning this carrier grants nothing. */
final readonly class ValidatedMlbTabularBundle
{
    public function __construct(
        private MlbTabularModelBundle $issuer,
        public array $bundle,
        public array $admission,
        public string $sha256,
        public int $bytes,
    ) {}

    public function assertOwned(): void
    {
        $this->issuer->assertValidatedOwner($this);
    }
}
