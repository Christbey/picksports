<?php

namespace App\Notifications;

use App\Application\Sports\ReadModels\PredictionDeliveryContext;
use App\Models\NotificationTemplate;
use App\Services\BettingRecommendations\RecommendationDisplay;
use App\Services\Predictions\PredictionDeliverySource;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\VonageMessage;
use Illuminate\Support\Str;

class BettingValueAlertFormatter
{
    public function __construct(private readonly PredictionDeliverySource $predictions) {}

    private function deliveryContext(Model|PredictionDeliveryContext $prediction, string $sport): PredictionDeliveryContext
    {
        $sport = strtolower($sport);
        if ($prediction instanceof PredictionDeliveryContext) {
            if ($prediction->sport !== $sport) {
                throw new \InvalidArgumentException('Prediction sport does not match the alert format.');
            }

            return $prediction;
        }

        return $this->predictions->context($prediction, $sport);
    }

    public function toMail(
        object $notifiable,
        Model|PredictionDeliveryContext $prediction,
        string $sport,
        float $expectedValue,
        string $recommendation,
        ?NotificationTemplate $template = null,
        array $selection = [],
    ): MailMessage {
        $prediction = $this->deliveryContext($prediction, $sport);
        $template = $this->compatibleTemplate($template, $selection);

        if ($template) {
            $data = $this->templateData($notifiable, $prediction, $sport, $expectedValue, $recommendation, $selection);

            $message = (new MailMessage)
                ->subject($template->renderSubject($data))
                ->greeting('')
                ->line($template->renderEmailBody($data));

            if ($selection !== []) {
                $message->line('Selection: '.$recommendation)
                    ->line('Market: '.($selection['type'] ?? 'Unavailable'))
                    ->line('Line: '.($selection['line'] ?? $selection['market_line'] ?? 'N/A'))
                    ->line('Stored price: '.RecommendationDisplay::price($selection['odds'] ?? null))
                    ->line('Bookmaker: '.($selection['bookmaker'] ?? 'Unavailable'))
                    ->line('Quote observed: '.($selection['odds_updated_at'] ?? 'Unavailable'));
            }

            return $message->action('View Full Analysis', $this->predictionUrl($prediction, $sport));
        }

        $game = $prediction->game;
        $homeTeam = $game->homeTeam->name ?? $game->homeTeam->school ?? 'Home Team';
        $awayTeam = $game->awayTeam->name ?? $game->awayTeam->school ?? 'Away Team';
        $gameTime = $this->kickoff($prediction)?->format('M j, Y g:i A T') ?? 'Time TBD';
        $edgeDisplay = $selection === [] ? '+'.round($expectedValue, 1).'%' : RecommendationDisplay::edge($selection);

        return (new MailMessage)
            ->subject("High-Value Betting Opportunity: {$awayTeam} @ {$homeTeam}")
            ->greeting("Value Alert: {$edgeDisplay}")
            ->line("We've identified a high-value betting opportunity for {$this->sportName($sport)}:")
            ->line("**{$awayTeam} @ {$homeTeam}**")
            ->line("Game Time: {$gameTime}")
            ->line("Recommendation: {$recommendation}")
            ->line('Market: '.($selection['type'] ?? 'Unavailable'))
            ->line('Line: '.($selection['line'] ?? $selection['market_line'] ?? 'N/A'))
            ->line("Model/market edge: {$edgeDisplay}")
            ->line('Stored price: '.RecommendationDisplay::price($selection['odds'] ?? null))
            ->line('Bookmaker: '.($selection['bookmaker'] ?? 'Unavailable'))
            ->line('Quote observed: '.($selection['odds_updated_at'] ?? 'Unavailable'))
            ->line('Confidence score: '.$this->confidence($selection, $prediction))
            ->action('View Full Analysis', $this->predictionUrl($prediction, $sport))
            ->line('This alert was sent based on your preferences. Manage your alert settings in your account.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(
        object $notifiable,
        Model|PredictionDeliveryContext $prediction,
        string $sport,
        float $expectedValue,
        string $recommendation,
        ?NotificationTemplate $template = null,
        array $selection = [],
    ): array {
        $prediction = $this->deliveryContext($prediction, $sport);
        $template = $this->compatibleTemplate($template, $selection);

        $game = $prediction->game;
        $payload = [
            'sport' => $sport,
            'game_id' => $game->id,
            'expected_value' => $expectedValue,
            'recommendation' => $recommendation,
            'confidence' => $selection['confidence'] ?? $prediction->confidenceScore,
            'edge' => $selection['edge'] ?? null,
            'edge_unit' => RecommendationDisplay::edgeUnit($selection),
            'line' => $selection['line'] ?? null,
            'odds' => $selection['odds'] ?? null,
            'bookmaker' => $selection['bookmaker'] ?? null,
            'odds_updated_at' => $selection['odds_updated_at'] ?? null,
            'game_time' => $this->kickoff($prediction)?->toIso8601String(),
            'home_team' => $game->homeTeam->name ?? $game->homeTeam->school,
            'away_team' => $game->awayTeam->name ?? $game->awayTeam->school,
            'url' => $this->predictionUrl($prediction, $sport),
        ];

        if ($template) {
            $data = $this->templateData($notifiable, $prediction, $sport, $expectedValue, $recommendation, $selection);
            $payload['title'] = $template->renderPushTitle($data);
            $payload['body'] = $template->renderPushBody($data);
        }

        return $payload;
    }

    public function toWhatsApp(
        object $notifiable,
        Model|PredictionDeliveryContext $prediction,
        string $sport,
        float $expectedValue,
        string $recommendation,
        ?NotificationTemplate $template = null,
        array $selection = [],
    ): string {
        $prediction = $this->deliveryContext($prediction, $sport);
        $template = $this->compatibleTemplate($template, $selection);

        if ($template) {
            $data = $this->templateData($notifiable, $prediction, $sport, $expectedValue, $recommendation, $selection);
            $body = trim((string) $template->renderSmsBody($data));

            if ($body !== '') {
                return $body.' '.$this->predictionUrl($prediction, $sport);
            }
        }

        $game = $prediction->game;
        $homeTeam = $game->homeTeam->name ?? $game->homeTeam->school ?? 'Home Team';
        $awayTeam = $game->awayTeam->name ?? $game->awayTeam->school ?? 'Away Team';
        $edgeDisplay = $selection === [] ? '+'.round($expectedValue, 1).'%' : RecommendationDisplay::edge($selection);

        return "Value Alert ({$edgeDisplay}): {$awayTeam} @ {$homeTeam}. {$recommendation}. ".$this->predictionUrl($prediction, $sport);
    }

    public function toVonage(
        object $notifiable,
        Model|PredictionDeliveryContext $prediction,
        string $sport,
        float $expectedValue,
        string $recommendation,
        ?NotificationTemplate $template = null,
        array $selection = [],
    ): VonageMessage {
        $prediction = $this->deliveryContext($prediction, $sport);
        $template = $this->compatibleTemplate($template, $selection);

        if ($template) {
            $data = $this->templateData($notifiable, $prediction, $sport, $expectedValue, $recommendation, $selection);
            $content = trim((string) $template->renderSmsBody($data));

            if ($content !== '') {
                return (new VonageMessage)
                    ->clientReference((string) $notifiable->id)
                    ->content($content);
            }
        }

        $game = $prediction->game;
        $homeTeam = $game->homeTeam->name ?? $game->homeTeam->school ?? 'Home Team';
        $awayTeam = $game->awayTeam->name ?? $game->awayTeam->school ?? 'Away Team';
        $edgeDisplay = $selection === [] ? '+'.round($expectedValue, 1).'%' : RecommendationDisplay::edge($selection);

        return (new VonageMessage)
            ->clientReference((string) $notifiable->id)
            ->content("Value Alert ({$edgeDisplay}): {$awayTeam} @ {$homeTeam}. {$recommendation}");
    }

    /**
     * @return array<string, mixed>
     */
    public function toWebPush(
        object $notifiable,
        Model|PredictionDeliveryContext $prediction,
        string $sport,
        float $expectedValue,
        string $recommendation,
        ?NotificationTemplate $template = null,
        array $selection = [],
    ): array {
        $prediction = $this->deliveryContext($prediction, $sport);
        $template = $this->compatibleTemplate($template, $selection);

        $game = $prediction->game;
        $homeTeam = $game->homeTeam->name ?? $game->homeTeam->school ?? 'Home Team';
        $awayTeam = $game->awayTeam->name ?? $game->awayTeam->school ?? 'Away Team';
        $title = "Value Alert: {$awayTeam} @ {$homeTeam}";
        $body = "Recommendation: {$recommendation}";

        if ($template) {
            $data = $this->templateData($notifiable, $prediction, $sport, $expectedValue, $recommendation, $selection);
            $title = $template->renderPushTitle($data) ?: $title;
            $body = $template->renderPushBody($data) ?: $body;
        }

        return [
            'title' => $title,
            'body' => $body,
            'icon' => '/apple-touch-icon.png',
            'badge' => '/icon-192.png',
            'tag' => 'betting-value-alert',
            'url' => $this->predictionUrl($prediction, $sport),
            'data' => [
                'sport' => strtolower($sport),
                'game_id' => $game->id,
                'url' => $this->predictionUrl($prediction, $sport),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function templateData(
        object $notifiable,
        PredictionDeliveryContext $prediction,
        string $sport,
        float $expectedValue,
        string $recommendation,
        array $selection = [],
    ): array {
        $game = $prediction->game;
        $homeTeam = $game->homeTeam->name ?? $game->homeTeam->school ?? 'Home Team';
        $awayTeam = $game->awayTeam->name ?? $game->awayTeam->school ?? 'Away Team';
        $edgeDisplay = $selection === [] ? '+'.round($expectedValue, 1).'%' : RecommendationDisplay::edge($selection);

        return [
            'user' => [
                'name' => $notifiable->name,
                'email' => $notifiable->email,
                'phone' => $notifiable->alertPreference?->phone_number ?? '',
            ],
            'prediction' => [
                'sport' => $this->sportName($sport),
                'game_description' => "{$awayTeam} @ {$homeTeam}",
                'home_team' => $homeTeam,
                'away_team' => $awayTeam,
                'pick_type' => isset($selection['type']) ? ucfirst($selection['type']) : $this->pickType($recommendation),
                'recommended_pick' => $recommendation,
                'edge_percentage' => $edgeDisplay,
                'edge_display' => $edgeDisplay,
                'edge_label' => $selection['edge_label'] ?? 'Model/market edge',
                'confidence' => $this->confidence($selection, $prediction),
                'odds' => RecommendationDisplay::price($selection['odds'] ?? null),
                'line' => $selection['line'] ?? $selection['market_line'] ?? 'N/A',
                'bookmaker' => $selection['bookmaker'] ?? 'Unavailable',
                'quote_observed' => $selection['odds_updated_at'] ?? 'Unavailable',
                'game_time' => $this->kickoff($prediction)?->format('g:i A T') ?? 'Time TBD',
                'game_date' => $this->kickoff($prediction)?->format('M j, Y') ?? $game->game_date->format('M j, Y'),
            ],
            'system' => [
                'app_name' => config('app.name'),
                'app_url' => config('app.url'),
                'support_email' => config('mail.from.address'),
            ],
        ];
    }

    private function pickType(string $recommendation): string
    {
        $recommendation = strtolower($recommendation);

        if (Str::contains($recommendation, 'spread')) {
            return 'Spread';
        }

        if (Str::contains($recommendation, ['over', 'under'])) {
            return 'Over/Under';
        }

        if (Str::contains($recommendation, 'moneyline')) {
            return 'Moneyline';
        }

        return 'Pick';
    }

    private function confidence(array $selection, PredictionDeliveryContext $prediction): string
    {
        $confidence = $selection === [] ? $prediction->confidenceScore : ($selection['confidence'] ?? null);

        return is_numeric($confidence) ? $confidence.($selection === [] ? '%' : '') : 'Unavailable';
    }

    private function compatibleTemplate(?NotificationTemplate $template, array $selection): ?NotificationTemplate
    {
        if ($template === null || $selection === []) {
            return $template;
        }

        $content = implode(' ', array_map(fn (string $field): string => (string) $template->{$field}, ['subject', 'email_body', 'sms_body', 'push_title', 'push_body']));

        return preg_match('/expected\\s+value|\\bEV\\b|\{prediction\\.(?:edge_percentage|edge_display)\}\\s*%/i', $content) === 1 ? null : $template;
    }

    private function kickoff(PredictionDeliveryContext $prediction): ?CarbonImmutable
    {
        return RecommendationDisplay::kickoff($prediction->game, $prediction->isCanonical() ? $prediction->source->sportEvent?->starts_at : null);
    }

    private function sportName(string $sport): string
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

    private function predictionUrl(PredictionDeliveryContext $prediction, string $sport): string
    {
        return url('/'.strtolower($sport).'/games/'.$prediction->game->getKey());
    }
}
