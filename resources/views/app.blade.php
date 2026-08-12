<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"  @class(['dark' => ($appearance ?? 'system') == 'dark'])>
    <head>
        @php
            $siteAssetStorage = app(\App\Services\SiteAssetStorage::class);
            $appName = config('app.name', 'PickSports');
            $betaEnabled = (bool) config('app.beta_enabled', true);
            $betaLabel = trim((string) config('app.beta_label', 'BETA'));
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
            $showAdsense = auth()->guest() && ($isPublicPage || request()->is([
                'nba/*',
                'wnba/*',
                'nfl/*',
                'cfb/*',
                'cbb/*',
                'wcbb/*',
                'mlb/*',
            ]));

            $defaultDescription = 'Sports predictions, analytics, and live game insights.';
            $descriptionMap = [
                '' => 'Beat the books with data-driven sports betting predictions and analytics.',
                'performance' => 'See verified PickSports performance metrics, ROI, and recent results.',
                'terms' => 'Review PickSports terms of service.',
                'privacy' => 'Review the PickSports privacy policy.',
                'responsible-gambling' => 'Learn PickSports responsible gambling principles and resources.',
            ];
            $metaTitle = $appName;
            $metaDescription = $descriptionMap[$path] ?? $defaultDescription;
            $ogImage = $siteAssetStorage->publicUrl('share').'?v=20260316-3';
            $ogImageAlt = 'PickSports PS gradient logo';
            $siteIcon512 = $siteAssetStorage->publicUrl('icon_512');
            $siteIcon192 = $siteAssetStorage->publicUrl('icon_192');
            $siteIcon512Maskable = $siteAssetStorage->publicUrl('icon_512_maskable');
            $siteAppleTouchIcon = $siteAssetStorage->publicUrl('apple_touch_icon');
            $siteFaviconSvg = $siteAssetStorage->publicUrl('favicon_svg');

            if ($path === 'login') {
                $metaTitle = 'Log in to PickSports';
                $metaDescription = 'Sign in to PickSports with passkey, Google, or email to access predictions and your March Madness bracket.';
            }

            if ($path === 'register') {
                $metaTitle = 'Create your PickSports account';
                $metaDescription = 'Create your PickSports account to join bracket groups, save picks, and compete on the leaderboard.';

                $inviteToken = trim((string) request()->query('invite', ''));
                if ($inviteToken !== '') {
                    $invitation = \App\Models\GroupInvitation::query()
                        ->with('group')
                        ->where('token', $inviteToken)
                        ->first();

                    if ($invitation && $invitation->isPending()) {
                        $groupName = $invitation->group?->name ?: 'a PickSports bracket group';
                        $metaTitle = "Join {$groupName} on {$appName}";
                        $metaDescription = "Accept your invitation to join {$groupName}, create your account, and complete your March Madness bracket on {$appName}.";
                    }
                }

                $joinToken = trim((string) request()->query('join', ''));
                if ($joinToken !== '') {
                    $joinLink = \App\Models\GroupJoinLink::query()
                        ->with('group')
                        ->where('token', $joinToken)
                        ->first();

                    if ($joinLink && $joinLink->isActive()) {
                        $groupName = $joinLink->group?->name ?: 'a PickSports bracket group';
                        $metaTitle = "Join {$groupName} on {$appName}";
                        $metaDescription = "Use this shared link to join {$groupName}, create your account, and fill out your March Madness bracket on {$appName}.";
                    }
                }
            }

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
                    'logo' => $siteIcon512,
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
        @if ($showAdsense)
            <meta name="google-adsense-account" content="ca-pub-2394264248851783">
        @endif
        <meta name="robots" content="{{ $isPublicPage ? 'index,follow' : 'noindex,nofollow' }}">
        <link rel="canonical" href="{{ $canonicalUrl }}">
        <link rel="alternate" hreflang="en-US" href="{{ $canonicalUrl }}">
        <link rel="alternate" hreflang="x-default" href="{{ $canonicalUrl }}">
        <meta property="og:type" content="website">
        <meta property="og:site_name" content="{{ $appName }}">
        <meta property="og:title" content="{{ $metaTitle }}">
        <meta property="og:description" content="{{ $metaDescription }}">
        <meta property="og:url" content="{{ $canonicalUrl }}">
        <meta property="og:image" content="{{ $ogImage }}">
        <meta property="og:image:alt" content="{{ $ogImageAlt }}">
        <meta property="og:image:width" content="1200">
        <meta property="og:image:height" content="1200">
        <meta name="twitter:card" content="summary_large_image">
        <meta name="twitter:title" content="{{ $metaTitle }}">
        <meta name="twitter:description" content="{{ $metaDescription }}">
        <meta name="twitter:image" content="{{ $ogImage }}">
        <meta name="twitter:image:alt" content="{{ $ogImageAlt }}">

        <link rel="icon" href="{{ $siteFaviconSvg }}" type="image/svg+xml">
        <link rel="shortcut icon" href="{{ $siteFaviconSvg }}" type="image/svg+xml">
        <link rel="alternate icon" href="/favicon.ico?v=ps-gradient-1" type="image/x-icon" sizes="32x32">
        <link rel="manifest" href="/site.webmanifest">
        <link rel="apple-touch-icon" href="{{ $siteAppleTouchIcon }}">
        <link rel="icon" type="image/png" sizes="192x192" href="{{ $siteIcon192 }}">
        <link rel="icon" type="image/png" sizes="512x512" href="{{ $siteIcon512 }}">

        <link rel="dns-prefetch" href="//fonts.bunny.net">
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" />
        @if ($showAdsense)
            <script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=ca-pub-2394264248851783" crossorigin="anonymous"></script>
        @endif

        <!-- Google Tag Manager -->
        <script>
            (function(w,d,s,l,i){
                var load=function(){
                    if(w.__psGtmLoaded){return;}w.__psGtmLoaded=true;
                    w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});
                    var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';
                    j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
                };
                var schedule=function(){
                    if('requestIdleCallback' in w){w.requestIdleCallback(load,{timeout:3000});}
                    else{w.setTimeout(load,1500);}
                };
                if(d.readyState==='complete'){schedule();}
                else{w.addEventListener('load',schedule,{once:true});}
            })(window,document,'script','dataLayer','GTM-P5NFGS3P');
        </script>
        <!-- End Google Tag Manager -->

        @foreach ($schemas as $schema)
            <script type="application/ld+json">{!! json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
        @endforeach

        @vite(['resources/js/app.ts', "resources/js/pages/{$page['component']}.vue"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        <!-- Google Tag Manager (noscript) -->
        <noscript><iframe src="https://www.googletagmanager.com/ns.html?id=GTM-P5NFGS3P"
        height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
        <!-- End Google Tag Manager (noscript) -->
        @if ($betaEnabled && $betaLabel !== '')
            <div
                class="pointer-events-none fixed top-3 right-3 z-[100] rounded-full border border-amber-300 bg-amber-100/95 px-2.5 py-1 text-[10px] font-bold tracking-[0.2em] text-amber-900 shadow-sm dark:border-amber-700 dark:bg-amber-900/90 dark:text-amber-100"
                aria-label="Beta indicator"
            >
                {{ $betaLabel }}
            </div>
        @endif
        @inertia
    </body>
</html>
