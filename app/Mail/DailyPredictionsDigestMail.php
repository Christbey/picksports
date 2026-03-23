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
        return new Envelope(
            subject: 'Your Daily Picks Digest',
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
