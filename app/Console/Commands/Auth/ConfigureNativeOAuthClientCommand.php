<?php

namespace App\Console\Commands\Auth;

use Illuminate\Console\Command;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;

class ConfigureNativeOAuthClientCommand extends Command
{
    protected $signature = 'auth:configure-native-oauth-client
        {--name=PickSports Native : Public client name}
        {--redirect-uri=* : Exact iOS or Android redirect URI}
        {--enable-device-flow : Also allow the OAuth device authorization grant}';

    protected $description = 'Create or update the public OAuth authorization-code client used by native apps';

    public function handle(ClientRepository $clients): int
    {
        $name = trim((string) $this->option('name'));
        $redirectUris = array_values(array_unique(array_filter(array_map(
            static fn (mixed $uri): string => trim((string) $uri),
            (array) $this->option('redirect-uri'),
        ))));

        if ($name === '' || $redirectUris === []) {
            $this->error('A client name and at least one exact --redirect-uri are required.');

            return self::FAILURE;
        }

        foreach ($redirectUris as $uri) {
            if (! $this->validRedirectUri($uri)) {
                $this->error("Invalid native redirect URI: {$uri}");

                return self::FAILURE;
            }
        }

        /** @var Client|null $client */
        $client = Passport::client()->newQuery()
            ->where('name', $name)
            ->where('revoked', false)
            ->get()
            ->first(fn (Client $candidate): bool => $candidate->hasGrantType('authorization_code'));

        if ($client === null) {
            $client = $clients->createAuthorizationCodeGrantClient(
                name: $name,
                redirectUris: $redirectUris,
                confidential: false,
                enableDeviceFlow: (bool) $this->option('enable-device-flow'),
            );
            $this->info('Created public native OAuth client.');
        } else {
            $clients->update($client, $name, $redirectUris);
            $client->forceFill([
                'secret' => null,
                'grant_types' => array_values(array_unique(array_filter([
                    'authorization_code',
                    'refresh_token',
                    $this->option('enable-device-flow') ? 'urn:ietf:params:oauth:grant-type:device_code' : null,
                ]))),
            ])->save();
            $this->info('Updated public native OAuth client.');
        }

        $this->table(['Client ID', 'Redirect URIs', 'PKCE'], [[
            $client->getKey(),
            implode(', ', $redirectUris),
            'required by native client (S256)',
        ]]);

        return self::SUCCESS;
    }

    private function validRedirectUri(string $uri): bool
    {
        $scheme = parse_url($uri, PHP_URL_SCHEME);

        return is_string($scheme)
            && $scheme !== ''
            && ! in_array(strtolower($scheme), ['javascript', 'data'], true)
            && (strtolower($scheme) !== 'http' || in_array(parse_url($uri, PHP_URL_HOST), ['localhost', '127.0.0.1', '::1'], true));
    }
}
