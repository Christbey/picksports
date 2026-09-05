<?php

namespace App\Services\DeveloperPlatform;

use App\DataTransferObjects\DeveloperPlatform\IssuedDeveloperWebhookEndpoint;
use App\Models\DeveloperOrganization;
use Illuminate\Support\Str;
use InvalidArgumentException;

class DeveloperWebhookEndpointIssuer
{
    /**
     * @param  list<string>  $events
     */
    public function issue(
        DeveloperOrganization $organization,
        string $name,
        string $url,
        array $events,
    ): IssuedDeveloperWebhookEndpoint {
        $name = trim($name);
        $events = collect($events)
            ->map(fn (mixed $event): string => trim((string) $event))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (! $organization->isActive() || $name === '' || $events === [] || ! $this->isSecureUrl($url)) {
            throw new InvalidArgumentException('Active organizations require a name, HTTPS URL, and at least one webhook event.');
        }

        $secret = Str::random(48);
        $endpoint = $organization->webhookEndpoints()->create([
            'name' => $name,
            'url' => $url,
            'signing_secret' => $secret,
            'events' => $events,
            'status' => 'active',
        ]);

        return new IssuedDeveloperWebhookEndpoint($endpoint, $secret);
    }

    private function isSecureUrl(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https';
    }
}
