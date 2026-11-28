<?php

namespace App\Http\Middleware;

use App\Support\Privacy\SensitiveDocument;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class AddSecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $sensitive = ! $request->is('api/*') && SensitiveDocument::matches($request);
        /** @var Response $response */
        $response = $sensitive && $request->isMethod('GET') && $request->header('X-Inertia')
            ? Inertia::location($request->fullUrl())
            : $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', $sensitive ? 'no-referrer' : 'strict-origin-when-cross-origin');
        if ($sensitive) {
            $response->headers->set('Cache-Control', 'private, no-store');
        }
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        $reportingEndpoints = 'csp="/api/v2/security/reports/csp", integrity="/api/v2/security/reports/integrity"';
        $response->headers->set('Reporting-Endpoints', $reportingEndpoints);

        $cspDirectives = [
            "default-src 'self'",
            "base-uri 'self'",
            // Meta Pixel's form transport posts to /tr/ in a hidden frame.
            "form-action 'self' https://www.facebook.com/tr/",
            "object-src 'none'",
            "frame-ancestors 'self'",
            "frame-src 'self' https://www.googletagmanager.com https://googleads.g.doubleclick.net https://tpc.googlesyndication.com https://ep2.adtrafficquality.google https://www.google.com https://www.facebook.com/tr/",
            // Inline bootstrap scripts/styles still exist in app.blade.php; keep allowed while in report-only.
            "script-src 'self' 'unsafe-inline' https://www.googletagmanager.com https://static.cloudflareinsights.com https://connect.facebook.net https://pagead2.googlesyndication.com https://ep2.adtrafficquality.google",
            "script-src-elem 'self' 'unsafe-inline' https://www.googletagmanager.com https://static.cloudflareinsights.com https://connect.facebook.net https://pagead2.googlesyndication.com https://ep2.adtrafficquality.google",
            "style-src 'self' 'unsafe-inline' https://fonts.bunny.net",
            "style-src-elem 'self' 'unsafe-inline' https://fonts.bunny.net",
            "font-src 'self' data: https://fonts.bunny.net https://cdn.jsdelivr.net/fontsource/fonts/instrument-sans@5.3.0/",
            "img-src 'self' data: https:",
            "connect-src 'self' https://www.google-analytics.com https://region1.google-analytics.com https://*.cloudflareinsights.com https://www.facebook.com https://connect.facebook.net https://ep1.adtrafficquality.google https://googleads.g.doubleclick.net",
            "manifest-src 'self'",
            "worker-src 'self' blob:",
            // Allow known policy names created by Vue, GTM/gtag, and Meta Pixel to prevent Trusted Types report noise.
            "trusted-types default vue goog#html connect.facebook.net/fbevents facebook.com/signals/iwl 'allow-duplicates'",
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
