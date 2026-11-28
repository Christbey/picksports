<?php

namespace App\Exceptions\Api;

use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/** Internal control flow: reject a response without committing downstream database writes. */
final class RollbackIdempotentResponse extends RuntimeException
{
    public function __construct(public readonly Response $response)
    {
        parent::__construct('The idempotent operation returned an unsuccessful response.');
    }
}
