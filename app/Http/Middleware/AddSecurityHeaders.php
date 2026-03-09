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

        $cspDirectives = [
            "default-src 'self'",
            "base-uri 'self'",
            "form-action 'self'",
            "object-src 'none'",
            "frame-ancestors 'self'",
            "frame-src 'self' https://www.googletagmanager.com",
            // Inline bootstrap scripts/styles still exist in app.blade.php; keep allowed while in report-only.
            "script-src 'self' 'unsafe-inline' https://www.googletagmanager.com https://static.cloudflareinsights.com https://connect.facebook.net",
            "script-src-elem 'self' 'unsafe-inline' https://www.googletagmanager.com https://static.cloudflareinsights.com https://connect.facebook.net",
            "style-src 'self' 'unsafe-inline' https://fonts.bunny.net",
            "style-src-elem 'self' 'unsafe-inline' https://fonts.bunny.net",
            "font-src 'self' data: https://fonts.bunny.net",
            "img-src 'self' data: https:",
            "connect-src 'self' https://www.google-analytics.com https://region1.google-analytics.com https://*.cloudflareinsights.com https://www.facebook.com https://connect.facebook.net",
            "manifest-src 'self'",
            "worker-src 'self' blob:",
            // Allow known policy names created by Vue and GTM/gtag to prevent Trusted Types report noise.
            "trusted-types default vue goog#html",
            'report-to csp',
        ];

        $cspReportOnly = implode('; ', [
            ...$cspDirectives,
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
