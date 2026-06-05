<?php

namespace App\Http\Middleware;

use App\Support\Api\V1ReplacementPathResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AddV1ApiDeprecationHeaders
{
    public function __construct(private readonly V1ReplacementPathResolver $replacementPathResolver) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ((bool) config('api.v1_usage_logging.deprecation_headers', true)) {
            $response->headers->set('X-API-Deprecated', 'true');
            $response->headers->set(
                'X-API-Replacement',
                $this->replacementPathResolver->resolve($request->path())
            );
        }

        return $response;
    }
}
