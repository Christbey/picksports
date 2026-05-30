<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AdminDailyEmailReportMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $report
     */
    public function __construct(
        public array $report,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: sprintf('PickSports Admin Email Report - %s', $this->report['date_label'] ?? now()->toFormattedDateString()),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.admin-daily-email-report',
            with: [
                'report' => $this->report,
                'dashboardUrl' => route('admin.healthchecks'),
            ],
        );
    }
}
