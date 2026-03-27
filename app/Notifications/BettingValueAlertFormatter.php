<?php

namespace App\Notifications;

use App\Models\NotificationTemplate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\VonageMessage;
use Illuminate\Support\Str;

class BettingValueAlertFormatter
{
    public function toMail(
        object $notifiable,
        Model $prediction,
        string $sport,
        float $expectedValue,
        string $recommendation,
        ?NotificationTemplate $template = null,
    ): MailMessage {
        if ($template) {
            $data = $this->templateData($notifiable, $prediction, $sport, $expectedValue, $recommendation);

            return (new MailMessage)
                ->subject($template->renderSubject($data))
                ->greeting('')
                ->line($template->renderEmailBody($data))
                ->action('View Full Analysis', $this->predictionUrl($prediction, $sport));
        }

        $game = $prediction->game;
        $homeTeam = $game->homeTeam->name ?? $game->homeTeam->school ?? 'Home Team';
        $awayTeam = $game->awayTeam->name ?? $game->awayTeam->school ?? 'Away Team';
        $gameTime = $game->game_date->format('M j, Y g:i A');
        $edgePercent = round($expectedValue, 1);

        return (new MailMessage)
            ->subject("High-Value Betting Opportunity: {$awayTeam} @ {$homeTeam}")
            ->greeting("Value Alert: {$edgePercent}% Expected Value")
            ->line("We've identified a high-value betting opportunity for {$this->sportName($sport)}:")
            ->line("**{$awayTeam} @ {$homeTeam}**")
            ->line("Game Time: {$gameTime}")
            ->line("Recommendation: {$recommendation}")
            ->line("Expected Value: +{$edgePercent}%")
            ->line("Confidence: {$prediction->confidence_score}%")
            ->action('View Full Analysis', $this->predictionUrl($prediction, $sport))
            ->line('This alert was sent based on your preferences. Manage your alert settings in your account.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(
        object $notifiable,
        Model $prediction,
        string $sport,
        float $expectedValue,
        string $recommendation,
        ?NotificationTemplate $template = null,
    ): array {
        $game = $prediction->game;
        $payload = [
            'sport' => $sport,
            'game_id' => $game->id,
            'expected_value' => $expectedValue,
            'recommendation' => $recommendation,
            'confidence' => $prediction->confidence_score,
            'game_time' => $game->game_date->toISOString(),
            'home_team' => $game->homeTeam->name ?? $game->homeTeam->school,
            'away_team' => $game->awayTeam->name ?? $game->awayTeam->school,
            'url' => $this->predictionUrl($prediction, $sport),
        ];

        if ($template) {
            $data = $this->templateData($notifiable, $prediction, $sport, $expectedValue, $recommendation);
            $payload['title'] = $template->renderPushTitle($data);
            $payload['body'] = $template->renderPushBody($data);
        }

        return $payload;
    }

    public function toWhatsApp(
        object $notifiable,
        Model $prediction,
        string $sport,
        float $expectedValue,
        string $recommendation,
        ?NotificationTemplate $template = null,
    ): string {
        if ($template) {
            $data = $this->templateData($notifiable, $prediction, $sport, $expectedValue, $recommendation);
            $body = trim((string) $template->renderSmsBody($data));

            if ($body !== '') {
                return $body.' '.$this->predictionUrl($prediction, $sport);
            }
        }

        $game = $prediction->game;
        $homeTeam = $game->homeTeam->name ?? $game->homeTeam->school ?? 'Home Team';
        $awayTeam = $game->awayTeam->name ?? $game->awayTeam->school ?? 'Away Team';
        $edgePercent = round($expectedValue, 1);

        return "Value Alert (+{$edgePercent}%): {$awayTeam} @ {$homeTeam}. {$recommendation}. ".$this->predictionUrl($prediction, $sport);
    }

    public function toVonage(
        object $notifiable,
        Model $prediction,
        string $sport,
        float $expectedValue,
        string $recommendation,
        ?NotificationTemplate $template = null,
    ): VonageMessage {
        if ($template) {
            $data = $this->templateData($notifiable, $prediction, $sport, $expectedValue, $recommendation);
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
        $edgePercent = round($expectedValue, 1);

        return (new VonageMessage)
            ->clientReference((string) $notifiable->id)
            ->content("Value Alert (+{$edgePercent}%): {$awayTeam} @ {$homeTeam}. {$recommendation}");
    }

    /**
     * @return array<string, mixed>
     */
    public function toWebPush(
        object $notifiable,
        Model $prediction,
        string $sport,
        float $expectedValue,
        string $recommendation,
        ?NotificationTemplate $template = null,
    ): array {
        $game = $prediction->game;
        $homeTeam = $game->homeTeam->name ?? $game->homeTeam->school ?? 'Home Team';
        $awayTeam = $game->awayTeam->name ?? $game->awayTeam->school ?? 'Away Team';
        $title = "Value Alert: {$awayTeam} @ {$homeTeam}";
        $body = "Recommendation: {$recommendation}";

        if ($template) {
            $data = $this->templateData($notifiable, $prediction, $sport, $expectedValue, $recommendation);
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
        Model $prediction,
        string $sport,
        float $expectedValue,
        string $recommendation,
    ): array {
        $game = $prediction->game;
        $homeTeam = $game->homeTeam->name ?? $game->homeTeam->school ?? 'Home Team';
        $awayTeam = $game->awayTeam->name ?? $game->awayTeam->school ?? 'Away Team';
        $edgePercent = round($expectedValue, 1);

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
                'pick_type' => $this->pickType($recommendation),
                'recommended_pick' => $recommendation,
                'edge_percentage' => "+{$edgePercent}%",
                'confidence' => $prediction->confidence_score.'%',
                'odds' => $this->oddsDisplay($recommendation),
                'game_time' => $game->game_date->format('g:i A'),
                'game_date' => $game->game_date->format('M j, Y'),
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

    private function oddsDisplay(string $recommendation): string
    {
        if (preg_match('/[-+]\d+/', $recommendation, $matches)) {
            return $matches[0];
        }

        return 'N/A';
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

    private function predictionUrl(Model $prediction, string $sport): string
    {
        return url('/'.strtolower($sport).'/predictions/'.$prediction->game_id);
    }
}
