<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class DailyPredictionsDigestMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array{headline:string,intro:string,highlights:array<int,string>}  $summary
     * @param  array<int, array<string, mixed>>  $predictions
     * @param  array<int, array<string, mixed>>  $playerProps
     */
    public function __construct(
        public User $user,
        public array $summary,
        public array $predictions,
        public array $playerProps,
    ) {}

    public function envelope(): Envelope
    {
        $topPrediction = $this->predictions[0] ?? null;
        $subject = $topPrediction
            ? sprintf(
                '%s Watchlist: %s',
                (string) ($topPrediction['sport'] ?? 'Today'),
                (string) ($topPrediction['bet_label'] ?? $topPrediction['pick'] ?? 'Daily Picks')
            )
            : 'Today\'s Picks Watchlist';

        return new Envelope(
            subject: $subject,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.daily-predictions-digest',
            with: [
                'user' => $this->user,
                'summary' => $this->summary,
                'predictions' => $this->predictions,
                'playerProps' => $this->playerProps,
                'dashboardUrl' => route('dashboard'),
            ],
        );
    }
}
