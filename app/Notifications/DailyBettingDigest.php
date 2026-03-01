<?php

namespace App\Notifications;

use App\Models\NotificationTemplate;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

class DailyBettingDigest extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Collection $topBets,
        public int $totalGamesAnalyzed,
        public Carbon $digestDate,
        public string $sport = 'cbb',
        public ?NotificationTemplate $template = null
    ) {}

    public function via(object $notifiable): array
    {
        $channels = [];

        if ($notifiable->alertPreference?->shouldReceiveEmailNotifications()) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        if ($this->template) {
            $data = $this->buildTemplateData($notifiable);

            $subject = $this->template->renderSubject($data);
            $body = $this->template->renderEmailBody($data);

            $mailMessage = (new MailMessage)
                ->subject($subject)
                ->greeting('')
                ->line($body);

            // Add action button if bets are available
            if ($this->topBets->isNotEmpty()) {
                $mailMessage->action('View All Predictions', url('/dashboard'));
            }

            return $mailMessage->line('Manage your alert preferences in your account settings.');
        }

        // Fallback to hardcoded content if no template
        return $this->buildFallbackEmail($notifiable);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'sport' => $this->sport,
            'digest_date' => $this->digestDate->toISOString(),
            'total_games' => $this->totalGamesAnalyzed,
            'bets_count' => $this->topBets->count(),
            'top_bets' => $this->topBets->map(fn ($bet) => [
                'sport' => $bet['sport'] ?? $this->sport,
                'type' => $bet['type'],
                'recommendation' => $bet['recommendation'],
                'edge' => $bet['edge'],
                'confidence' => $bet['confidence'],
            ])->toArray(),
        ];
    }

    protected function buildTemplateData(object $notifiable): array
    {
        $betsCount = $this->topBets->count();
        $hasBets = $betsCount > 0;

        return [
            'user' => [
                'name' => $notifiable->name,
                'email' => $notifiable->email,
            ],
            'digest' => [
                'date' => $this->digestDate->format('F j, Y'),
                'total_games' => $this->totalGamesAnalyzed,
                'bets_count' => $betsCount,
                'has_bets' => $hasBets,
                'empty_message' => $this->getEmptyMessage(),
                'bets_table' => $hasBets ? $this->buildBetsTable() : '',
            ],
            'system' => [
                'app_name' => config('app.name'),
                'app_url' => config('app.url'),
                'support_email' => config('mail.from.address'),
            ],
        ];
    }

    protected function buildBetsTable(): string
    {
        $rows = [];

        foreach ($this->topBets as $bet) {
            $game = $bet['game'];
            $homeTeam = $game->homeTeam->school ?? 'Home';
            $awayTeam = $game->awayTeam->school ?? 'Away';
            $gameTime = $game->game_date->format('g:i A');

            $matchup = "{$awayTeam} @ {$homeTeam}";
            $sport = strtoupper((string) ($bet['sport'] ?? $this->sport));
            $betType = ucfirst($bet['type']);
            $pick = $bet['recommendation'];
            $edge = $this->formatEdge($bet);
            $confidence = round($bet['confidence'], 0).'%';

            $rows[] = "| {$sport} | {$matchup} | {$gameTime} | {$betType} | {$pick} | {$edge} | {$confidence} |";
        }

        $header = "| Sport | Game | Time | Type | Pick | Edge | Confidence |\n";
        $separator = "|-------|------|------|------|------|------|------------|\n";

        return $header.$separator.implode("\n", $rows);
    }

    protected function formatEdge(array $bet): string
    {
        if ($bet['type'] === 'moneyline') {
            return '+'.round($bet['edge'], 1).'%';
        }

        return round($bet['edge'], 1).' pts';
    }

    protected function getEmptyMessage(): string
    {
        return sprintf(
            'We analyzed %d games but found no qualifying model edges today. Check back tomorrow!',
            $this->totalGamesAnalyzed
        );
    }

    protected function buildFallbackEmail(object $notifiable): MailMessage
    {
        $dateStr = $this->digestDate->format('F j, Y');
        $betsCount = $this->topBets->count();

        $subject = $betsCount > 0
            ? "Your Daily Betting Digest - {$betsCount} Top Opportunities for {$dateStr}"
            : "Your Daily Betting Digest - {$dateStr}";

        $mailMessage = (new MailMessage)
            ->subject($subject)
            ->greeting("Good morning, {$notifiable->name}!");

        if ($betsCount === 0) {
            return $mailMessage
                ->line($this->getEmptyMessage())
                ->line('We\'ll continue monitoring games and send you alerts when valuable opportunities appear.');
        }

        $mailMessage
            ->line("Here are your top {$betsCount} betting opportunities for today:")
            ->line('')
            ->line($this->buildBetsTable())
            ->line('')
            ->line("These picks were selected from {$this->totalGamesAnalyzed} games based on our advanced analytics and your subscription tier.")
            ->action('View All Predictions', url('/dashboard'))
            ->line('Manage your alert preferences in your account settings.');

        return $mailMessage;
    }

    protected function getSportName(): string
    {
        return match (strtolower($this->sport)) {
            'nfl' => 'NFL',
            'nba' => 'NBA',
            'cbb' => 'NCAA Men\'s Basketball',
            'wcbb' => 'NCAA Women\'s Basketball',
            'mlb' => 'MLB',
            'cfb' => 'NCAA Football',
            'wnba' => 'WNBA',
            default => strtoupper($this->sport),
        };
    }
}
