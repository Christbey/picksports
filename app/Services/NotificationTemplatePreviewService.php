<?php

namespace App\Services;

use App\Models\NFL\Game;
use App\Models\NotificationTemplate;
use App\Models\User;
use App\Services\BettingRecommendations\GameBettingRecommendationService;
use Carbon\Carbon;
use Illuminate\Mail\Markdown;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Throwable;

class NotificationTemplatePreviewService
{
    public function __construct(
        private readonly SiteAssetStorage $siteAssetStorage,
        private readonly GameBettingRecommendationService $gameBettingRecommendationService,
    ) {}

    private const SPORT_GAME_MODELS = [
        'nfl' => Game::class,
        'nba' => \App\Models\NBA\Game::class,
        'cbb' => \App\Models\CBB\Game::class,
        'wcbb' => \App\Models\WCBB\Game::class,
        'mlb' => \App\Models\MLB\Game::class,
        'cfb' => \App\Models\CFB\Game::class,
        'wnba' => \App\Models\WNBA\Game::class,
    ];

    /**
     * @param  array<string, mixed>  $templateFields
     * @return array<string, mixed>
     */
    public function buildPreview(User $user, array $templateFields, string $context, string $sport, Carbon $date): array
    {
        $template = new NotificationTemplate([
            'name' => $templateFields['name'] ?? 'Preview Template',
            'subject' => $templateFields['subject'] ?? '',
            'email_body' => $templateFields['email_body'] ?? '',
            'sms_body' => $templateFields['sms_body'] ?? '',
            'push_title' => $templateFields['push_title'] ?? '',
            'push_body' => $templateFields['push_body'] ?? '',
            'active' => true,
        ]);

        $payload = $this->bettingValuePayload($sport);
        $renderedEmailBody = $template->renderEmailBody($payload['data']);

        return [
            'context' => 'betting_value_alert',
            'subject' => $template->renderSubject($payload['data']),
            'email_body' => $renderedEmailBody,
            'email_html' => $this->renderEmailHtml($renderedEmailBody),
            'data' => $payload['data'],
            'meta' => $payload['meta'],
        ];
    }

    /**
     * @return array{data:array<string,mixed>,meta:array<string,mixed>}
     */
    private function bettingValuePayload(string $sport): array
    {
        $sport = strtolower($sport);
        $gameModel = self::SPORT_GAME_MODELS[$sport] ?? null;

        if (! $gameModel) {
            return $this->fallbackBettingValuePayload($sport, 'Unsupported sport for preview.');
        }

        $game = $gameModel::query()
            ->where('game_date', '>', now())
            ->whereNotNull('odds_data')
            ->whereHas('prediction')
            ->with(['homeTeam', 'awayTeam', 'prediction'])
            ->orderBy('game_date')
            ->first();

        if (! $game || ! $game->prediction) {
            return $this->fallbackBettingValuePayload($sport, 'No live game with prediction + odds found. Showing fallback sample.');
        }

        $recommendations = $this->gameBettingRecommendationService->forGame($game, $sport);

        if ($recommendations === []) {
            return $this->fallbackBettingValuePayload($sport, 'Betting value calculation is not configured for this sport yet.');
        }

        $topRecommendation = collect($recommendations)->sortByDesc('edge')->first();

        $homeTeam = $this->teamDisplayName($game->homeTeam);
        $awayTeam = $this->teamDisplayName($game->awayTeam);
        $prediction = $game->prediction;

        $edge = $topRecommendation['edge'] ?? 0;
        $recommendation = $topRecommendation['recommendation'] ?? 'No recommendation available';
        $pickType = ucfirst((string) ($topRecommendation['type'] ?? 'pick'));

        $data = [
            'user' => [
                'name' => 'Sample User',
                'email' => 'sample@example.com',
                'phone' => '',
            ],
            'prediction' => [
                'sport' => $this->sportDisplayName($sport),
                'game_description' => "{$awayTeam} @ {$homeTeam}",
                'home_team' => $homeTeam,
                'away_team' => $awayTeam,
                'pick_type' => $pickType,
                'recommended_pick' => $recommendation,
                'edge_percentage' => (is_numeric($edge) ? ((float) $edge >= 0 ? '+' : '').round((float) $edge, 1).'%' : (string) $edge),
                'confidence' => round((float) $prediction->confidence_score, 0).'%',
                'odds' => isset($topRecommendation['odds']) ? (string) $topRecommendation['odds'] : 'N/A',
                'game_time' => $game->game_date->format('g:i A'),
                'game_date' => $game->game_date->format('M j, Y'),
            ],
            'system' => [
                'app_name' => config('app.name'),
                'app_url' => config('app.url'),
                'support_email' => config('mail.from.address'),
            ],
        ];

        return [
            'data' => $data,
            'meta' => [
                'game_id' => $game->id,
                'recommendations' => array_values($recommendations),
            ],
        ];
    }

    private function renderEmailHtml(string $body): string
    {
        if (trim($body) === '') {
            return '';
        }

        try {
            $markdown = app(Markdown::class);

            if ($this->containsFullMailLayout($body)) {
                return $this->replaceLaravelBranding(Blade::render($body));
            }

            if ($this->containsBladeEmailMarkup($body)) {
                $body = Blade::render($body);
            } else {
                $body = Str::markdown($body);
            }

            $html = $markdown->render('mail::message', [
                'slot' => new HtmlString($body),
            ])->toHtml();

            return $this->replaceLaravelBranding($html);
        } catch (Throwable) {
            return '<div style="white-space: pre-wrap;">'.e($body).'</div>';
        }
    }

    private function containsFullMailLayout(string $body): bool
    {
        return str_contains($body, '<x-mail::message')
            || str_contains($body, "@component('mail::message")
            || str_contains($body, '@component("mail::message');
    }

    private function containsBladeEmailMarkup(string $body): bool
    {
        return str_contains($body, '<x-mail::')
            || str_contains($body, '@component(\'mail::')
            || str_contains($body, '@if')
            || str_contains($body, '@foreach')
            || str_contains($body, '{{');
    }

    private function replaceLaravelBranding(string $html): string
    {
        $logo = $this->siteAssetStorage->publicUrl('favicon_svg');

        $replaced = str_replace(
            [
                'https://laravel.com/img/notification-logo-v2.png',
                'https://laravel.com/img/notification-logo-v2.1.png',
                'https://laravel.com/img/notification-logo.png',
                'alt="Laravel Logo"',
                '>Laravel<',
            ],
            [
                $logo,
                $logo,
                $logo,
                'alt="PickSports"',
                '>PickSports<',
            ],
            $html
        );

        $replaced = (string) preg_replace(
            '#https://laravel\.com/img/notification-logo[^"\']*#i',
            $logo,
            $replaced
        );

        return str_replace('https://laravel.com', (string) config('app.url'), $replaced);
    }

    /**
     * @return array{data:array<string,mixed>,meta:array<string,mixed>}
     */
    private function fallbackBettingValuePayload(string $sport, string $note): array
    {
        $data = [
            'user' => [
                'name' => 'Sample User',
                'email' => 'sample@example.com',
                'phone' => '',
            ],
            'prediction' => [
                'sport' => $this->sportDisplayName($sport),
                'game_description' => 'Away Team @ Home Team',
                'home_team' => 'Home Team',
                'away_team' => 'Away Team',
                'pick_type' => 'Spread',
                'recommended_pick' => 'Bet Home -3.5',
                'edge_percentage' => '+5.2%',
                'confidence' => '68%',
                'odds' => '-110',
                'game_time' => now()->addHours(2)->format('g:i A'),
                'game_date' => now()->addHours(2)->format('M j, Y'),
            ],
            'system' => [
                'app_name' => config('app.name'),
                'app_url' => config('app.url'),
                'support_email' => config('mail.from.address'),
            ],
        ];

        return [
            'data' => $data,
            'meta' => [
                'note' => $note,
                'recommendations' => [],
            ],
        ];
    }

    private function teamDisplayName(mixed $team, string $fallback = 'Unknown'): string
    {
        if (! $team) {
            return $fallback;
        }

        $name = trim(implode(' ', array_filter([
            $team->location ?? null,
            $team->name ?? null,
        ])));

        return $team->school
            ?? ($name !== '' ? $name : null)
            ?? $team->abbreviation
            ?? $fallback;
    }

    private function sportDisplayName(string $sport): string
    {
        return match (strtolower($sport)) {
            'nfl' => 'NFL',
            'nba' => 'NBA',
            'cbb' => 'NCAA Men\'s Basketball',
            'wcbb' => 'NCAA Women\'s Basketball',
            'mlb' => 'MLB',
            'cfb' => 'NCAA Football',
            'wnba' => 'WNBA',
            default => strtoupper($sport),
        };
    }
}
