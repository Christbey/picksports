<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"  @class(['dark' => ($appearance ?? 'system') == 'dark'])>
    <head>
        @php
            $appName = config('app.name', 'PickSports');
            $baseUrl = rtrim(config('app.url', 'https://picksports.app'), '/');
            $canonicalUrl = url()->current();
            $path = trim(request()->path(), '/');
            $isPublicPage = ! request()->is([
                'dashboard',
                'my-bets',
                'settings*',
                'admin*',
                'subscription*',
                '*-predictions',
                '*-team-metrics',
                '*-player-stats',
                '*-player-props',
                'nba/*',
                'wnba/*',
                'nfl/*',
                'cfb/*',
                'cbb/*',
                'wcbb/*',
                'mlb/*',
            ]);

            $defaultDescription = 'Sports predictions, analytics, and live game insights.';
            $descriptionMap = [
                '' => 'Beat the books with data-driven sports betting predictions and analytics.',
                'performance' => 'See verified PickSports performance metrics, ROI, and recent results.',
                'terms' => 'Review PickSports terms of service.',
                'privacy' => 'Review the PickSports privacy policy.',
                'responsible-gambling' => 'Learn PickSports responsible gambling principles and resources.',
            ];
            $metaDescription = $descriptionMap[$path] ?? $defaultDescription;
            $ogImage = $baseUrl.'/icon-512.png?v=ps-gradient-2';

            $segments = array_values(array_filter(explode('/', $path)));
            $breadcrumbItems = [[
                '@type' => 'ListItem',
                'position' => 1,
                'name' => 'Home',
                'item' => $baseUrl.'/',
            ]];

            $runningPath = '';
            foreach ($segments as $index => $segment) {
                $runningPath .= '/'.$segment;
                $breadcrumbItems[] = [
                    '@type' => 'ListItem',
                    'position' => $index + 2,
                    'name' => ucfirst(str_replace('-', ' ', $segment)),
                    'item' => $baseUrl.$runningPath,
                ];
            }

            $schemas = [
                [
                    '@context' => 'https://schema.org',
                    '@type' => 'Organization',
                    'name' => $appName,
                    'url' => $baseUrl,
                    'logo' => $baseUrl.'/icon-512.png',
                ],
                [
                    '@context' => 'https://schema.org',
                    '@type' => 'WebSite',
                    'name' => $appName,
                    'url' => $baseUrl,
                ],
                [
                    '@context' => 'https://schema.org',
                    '@type' => 'BreadcrumbList',
                    'itemListElement' => $breadcrumbItems,
                ],
            ];

            if (request()->is(['terms', 'privacy', 'responsible-gambling'])) {
                $schemas[] = [
                    '@context' => 'https://schema.org',
                    '@type' => 'Article',
                    'headline' => ucfirst(str_replace('-', ' ', $path)).' - '.$appName,
                    'mainEntityOfPage' => $canonicalUrl,
                    'publisher' => [
                        '@type' => 'Organization',
                        'name' => $appName,
                    ],
                ];
            }

            if (request()->is(['nba/games/*', 'wnba/games/*', 'nfl/games/*', 'mlb/games/*', 'cbb/games/*', 'wcbb/games/*'])) {
                $schemas[] = [
                    '@context' => 'https://schema.org',
                    '@type' => 'SportsEvent',
                    'name' => $appName.' Game Analysis',
                    'url' => $canonicalUrl,
                ];
            }
        @endphp
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="color-scheme" content="light dark">

        {{-- Inline script to detect system dark mode preference and apply it immediately --}}
        <script>
            (function() {
                const appearance = '{{ $appearance ?? "system" }}';
                const isStandalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;

                if (appearance === 'system') {
                    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

                    if (prefersDark) {
                        document.documentElement.classList.add('dark');
                    }
                }

                if (isStandalone) {
                    document.documentElement.classList.add('is-standalone');
                }
            })();
        </script>

        {{-- Inline style to set the HTML background color based on our theme in app.css --}}
        <style>
            html {
                background-color: oklch(1 0 0);
            }

            html.dark {
                background-color: oklch(0.145 0 0);
            }
        </style>

        <title inertia>{{ $appName }}</title>

        <meta name="application-name" content="{{ $appName }}">
        <meta name="mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-title" content="{{ $appName }}">
        <meta name="apple-mobile-web-app-status-bar-style" content="default">
        <meta name="format-detection" content="telephone=no">
        <meta name="theme-color" content="#ffffff" media="(prefers-color-scheme: light)">
        <meta name="theme-color" content="#0b0f19" media="(prefers-color-scheme: dark)">
        <meta name="description" content="{{ $metaDescription }}">
        <meta name="robots" content="{{ $isPublicPage ? 'index,follow' : 'noindex,nofollow' }}">
        <link rel="canonical" href="{{ $canonicalUrl }}">
        <link rel="alternate" hreflang="en-US" href="{{ $canonicalUrl }}">
        <link rel="alternate" hreflang="x-default" href="{{ $canonicalUrl }}">
        <meta property="og:type" content="website">
        <meta property="og:site_name" content="{{ $appName }}">
        <meta property="og:title" content="{{ $appName }}">
        <meta property="og:description" content="{{ $metaDescription }}">
        <meta property="og:url" content="{{ $canonicalUrl }}">
        <meta property="og:image" content="{{ $ogImage }}">
        <meta property="og:image:alt" content="PickSports PS gradient logo">
        <meta name="twitter:card" content="summary_large_image">
        <meta name="twitter:title" content="{{ $appName }}">
        <meta name="twitter:description" content="{{ $metaDescription }}">
        <meta name="twitter:image" content="{{ $ogImage }}">

        <link rel="icon" href="/favicon.svg?v=ps-gradient-1" type="image/svg+xml">
        <link rel="shortcut icon" href="/favicon.svg?v=ps-gradient-1" type="image/svg+xml">
        <link rel="alternate icon" href="/favicon.ico?v=ps-gradient-1" type="image/x-icon" sizes="32x32">
        <link rel="manifest" href="/site.webmanifest">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">
        <link rel="icon" type="image/png" sizes="192x192" href="/icon-192.png">
        <link rel="icon" type="image/png" sizes="512x512" href="/icon-512.png">

        <link rel="dns-prefetch" href="//fonts.bunny.net">
        <link rel="dns-prefetch" href="//github.com">
        <link rel="dns-prefetch" href="//laravel.com">
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" />

        <script async src="https://www.googletagmanager.com/gtag/js?id=G-6E20VBR3CR"></script>
        <script>
            window.dataLayer = window.dataLayer || [];
            function gtag(){dataLayer.push(arguments);}
            gtag('js', new Date());
            gtag('config', 'G-6E20VBR3CR');
        </script>

        @foreach ($schemas as $schema)
            <script type="application/ld+json">{!! json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
        @endforeach

        @vite(['resources/js/app.ts', "resources/js/pages/{$page['component']}.vue"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
