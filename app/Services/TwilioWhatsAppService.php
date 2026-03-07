<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class TwilioWhatsAppService
{
    public function isConfigured(): bool
    {
        return $this->accountSid() !== ''
            && $this->authToken() !== ''
            && $this->fromNumber() !== '';
    }

    public function sendMessage(string $to, string $body): bool
    {
        if (! $this->isConfigured()) {
            Log::warning('Twilio WhatsApp is not configured. Skipping alert dispatch.');

            return false;
        }

        $to = $this->formatWhatsAppAddress($to);
        $from = $this->formatWhatsAppAddress($this->fromNumber());

        try {
            $response = Http::withBasicAuth($this->accountSid(), $this->authToken())
                ->asForm()
                ->post($this->messagesEndpoint(), [
                    'From' => $from,
                    'To' => $to,
                    'Body' => $body,
                ]);

            if (! $response->successful()) {
                Log::warning('Twilio WhatsApp request failed.', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return false;
            }
        } catch (Throwable $e) {
            Log::warning('Twilio WhatsApp request threw an exception.', [
                'message' => $e->getMessage(),
            ]);

            return false;
        }

        return true;
    }

    private function messagesEndpoint(): string
    {
        return sprintf(
            'https://api.twilio.com/2010-04-01/Accounts/%s/Messages.json',
            $this->accountSid()
        );
    }

    private function formatWhatsAppAddress(string $phone): string
    {
        $phone = trim($phone);
        $phone = preg_replace('/[^0-9+]/', '', $phone) ?? '';

        if ($phone !== '' && $phone[0] !== '+') {
            $phone = '+'.$phone;
        }

        return 'whatsapp:'.$phone;
    }

    private function accountSid(): string
    {
        return (string) config('services.twilio_whatsapp.account_sid', '');
    }

    private function authToken(): string
    {
        return (string) config('services.twilio_whatsapp.auth_token', '');
    }

    private function fromNumber(): string
    {
        return (string) config('services.twilio_whatsapp.from', '');
    }
}
