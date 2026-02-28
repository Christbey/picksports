<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AddSecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        $reportingEndpoints = 'csp="/api/v1/security/reports/csp", integrity="/api/v1/security/reports/integrity"';
        $response->headers->set('Reporting-Endpoints', $reportingEndpoints);

        $cspReportOnly = implode('; ', [
            "default-src 'self'",
            "base-uri 'self'",
            "form-action 'self'",
            "object-src 'none'",
            "frame-ancestors 'self'",
            "upgrade-insecure-requests",
            "require-trusted-types-for 'script'",
            "trusted-types default",
            'report-to csp',
        ]);
        $response->headers->set('Content-Security-Policy-Report-Only', $cspReportOnly);

        // Start in report-only mode first to avoid breaking third-party assets unexpectedly.
        $response->headers->set('Integrity-Policy-Report-Only', 'blocked-destinations=(script), endpoints=(integrity)');

        if ($request->isSecure() || app()->environment('production')) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
