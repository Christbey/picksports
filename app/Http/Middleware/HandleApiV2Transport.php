<?php

namespace App\Http\Middleware;

use App\Support\Api\ApiV2ErrorResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class HandleApiV2Transport
{
    public function __construct(private readonly ApiV2ErrorResponse $errorResponse) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->errorResponse->appliesTo($request)) {
            return $next($request);
        }

        $this->errorResponse->requestId($request);

        return $this->errorResponse->normalize($request, $next($request));
    }
}
