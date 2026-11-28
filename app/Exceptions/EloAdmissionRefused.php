<?php

namespace App\Exceptions;

use RuntimeException;

class EloAdmissionRefused extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly array $considered, public readonly array $limits, public readonly array $evidence = [])
    {
        parent::__construct('Guarded Elo admission refused: '.$reason.'. Considered '.json_encode($considered, JSON_THROW_ON_ERROR).'; limits '.json_encode($limits, JSON_THROW_ON_ERROR).'.');
    }

    public function context(): array
    {
        return ['reason' => $this->reason, 'considered' => $this->considered, 'limits' => $this->limits, 'evidence' => $this->evidence];
    }
}
