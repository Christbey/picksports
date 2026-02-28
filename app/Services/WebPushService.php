<?php

namespace App\Services;

use App\Models\User;
use App\Models\WebPushSubscription;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class WebPushService
{
    public function isConfigured(): bool
    {
        return ! empty(config('services.web_push.public_key'))
            && ! empty(config('services.web_push.private_key'))
            && ! empty(config('services.web_push.subject'));
    }

    public function publicKey(): ?string
    {
        $key = config('services.web_push.public_key');

        return is_string($key) && $key !== '' ? $key : null;
    }

    public function registerSubscription(User $user, array $payload, ?string $userAgent = null): WebPushSubscription
    {
        $endpoint = (string) ($payload['endpoint'] ?? '');
        $keys = $payload['keys'] ?? [];
        $publicKey = (string) ($keys['p256dh'] ?? '');
        $authToken = (string) ($keys['auth'] ?? '');
        $contentEncoding = (string) ($payload['contentEncoding'] ?? 'aes128gcm');

        if ($endpoint === '' || $publicKey === '' || $authToken === '') {
            throw new RuntimeException('Invalid push subscription payload.');
        }

        return WebPushSubscription::updateOrCreate(
            ['endpoint' => $endpoint],
            [
                'user_id' => $user->id,
                'public_key' => $publicKey,
                'auth_token' => $authToken,
                'content_encoding' => $contentEncoding,
                'user_agent' => $userAgent,
                'expired_at' => null,
            ]
        );
    }

    public function removeSubscription(User $user, ?string $endpoint = null): int
    {
        $query = $user->webPushSubscriptions();

        if ($endpoint) {
            $query->where('endpoint', $endpoint);
        }

        return $query->delete();
    }

    public function sendToUser(User $user, array $payload): array
    {
        $subscriptions = $user->webPushSubscriptions()->active()->get();

        $sent = 0;
        $failed = 0;

        foreach ($subscriptions as $subscription) {
            if ($this->sendToSubscription($subscription, $payload)) {
                $sent++;
            } else {
                $failed++;
            }
        }

        return [
            'subscriptions' => $subscriptions->count(),
            'sent' => $sent,
            'failed' => $failed,
        ];
    }

    public function sendToSubscription(WebPushSubscription $subscription, array $payload): bool
    {
        if (! $this->isConfigured()) {
            Log::warning('Web push send skipped because VAPID config is incomplete.');

            return false;
        }

        try {
            $request = $this->buildEncryptedPushRequest($subscription, $payload);
            $response = $this->post(
                $subscription->endpoint,
                $request['body'],
                $request['headers']
            );
        } catch (\Throwable $e) {
            Log::warning('Web push send failed during request build.', [
                'subscription_id' => $subscription->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        if (in_array($response['status'], [201, 202], true)) {
            $subscription->forceFill([
                'last_used_at' => now(),
            ])->save();

            return true;
        }

        if (in_array($response['status'], [404, 410], true)) {
            $subscription->forceFill([
                'expired_at' => now(),
            ])->save();
        }

        Log::warning('Web push provider returned non-success status.', [
            'subscription_id' => $subscription->id,
            'status' => $response['status'],
            'response' => $response['body'],
        ]);

        return false;
    }

    /**
     * @return array{body: string, headers: array<int, string>}
     */
    private function buildEncryptedPushRequest(WebPushSubscription $subscription, array $payload): array
    {
        $clientPublicKey = $this->base64UrlDecode($subscription->public_key);
        $authSecret = $this->base64UrlDecode($subscription->auth_token);

        if ($clientPublicKey === '' || $authSecret === '') {
            throw new RuntimeException('Subscription key material is invalid.');
        }

        $serverKey = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);

        if (! $serverKey) {
            throw new RuntimeException('Failed to generate ephemeral server key.');
        }

        $serverKeyDetails = openssl_pkey_get_details($serverKey);
        $serverEc = $serverKeyDetails['ec'] ?? null;
        $serverPublicRaw = isset($serverEc['x'], $serverEc['y'])
            ? "\x04".$serverEc['x'].$serverEc['y']
            : '';

        if ($serverPublicRaw === '') {
            throw new RuntimeException('Failed to derive ephemeral public key.');
        }

        $clientPublicKeyPem = $this->rawEcPublicKeyToPem($clientPublicKey);
        $sharedSecret = openssl_pkey_derive($clientPublicKeyPem, $serverKey, 32);

        if (! is_string($sharedSecret) || $sharedSecret === '') {
            throw new RuntimeException('Failed to derive ECDH shared secret.');
        }

        $salt = random_bytes(16);
        $context = "WebPush: info\x00".$clientPublicKey.$serverPublicRaw;
        $prkAuth = $this->hkdfExtract($authSecret, $sharedSecret);
        $ikm = $this->hkdfExpand($prkAuth, $context, 32);
        $prk = $this->hkdfExtract($salt, $ikm);
        $cek = $this->hkdfExpand($prk, "Content-Encoding: aes128gcm\x00", 16);
        $nonce = $this->hkdfExpand($prk, "Content-Encoding: nonce\x00", 12);

        $plaintext = json_encode($this->normalizePayload($payload), JSON_UNESCAPED_SLASHES)."\x02";
        $ciphertext = openssl_encrypt(
            $plaintext,
            'aes-128-gcm',
            $cek,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag
        );

        if (! is_string($ciphertext) || ! isset($tag)) {
            throw new RuntimeException('Failed to encrypt push payload.');
        }

        $recordSize = 4096;
        $body = $salt.pack('N', $recordSize).chr(strlen($serverPublicRaw)).$serverPublicRaw.$ciphertext.$tag;
        $audience = $this->audienceFromEndpoint($subscription->endpoint);
        $jwt = $this->createVapidJwt($audience);
        $publicKey = (string) config('services.web_push.public_key');

        return [
            'body' => $body,
            'headers' => [
                'TTL: 60',
                'Content-Type: application/octet-stream',
                'Content-Encoding: aes128gcm',
                "Authorization: vapid t={$jwt}, k={$publicKey}",
                'Urgency: normal',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizePayload(array $payload): array
    {
        return [
            'title' => $payload['title'] ?? config('app.name'),
            'body' => $payload['body'] ?? 'New notification',
            'icon' => $payload['icon'] ?? '/apple-touch-icon.png',
            'badge' => $payload['badge'] ?? '/icon-192.png',
            'url' => $payload['url'] ?? '/',
            'tag' => $payload['tag'] ?? null,
            'data' => $payload['data'] ?? [],
        ];
    }

    /**
     * @return array{status: int, body: string}
     */
    private function post(string $endpoint, string $body, array $headers): array
    {
        $ch = curl_init($endpoint);

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
        ]);

        $responseBody = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($responseBody === false) {
            throw new RuntimeException("Push provider request failed: {$curlError}");
        }

        return [
            'status' => $status,
            'body' => is_string($responseBody) ? $responseBody : '',
        ];
    }

    private function audienceFromEndpoint(string $endpoint): string
    {
        $parts = parse_url($endpoint);

        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            throw new RuntimeException('Invalid push endpoint URL.');
        }

        $port = isset($parts['port']) ? ":{$parts['port']}" : '';

        return "{$parts['scheme']}://{$parts['host']}{$port}";
    }

    private function createVapidJwt(string $audience): string
    {
        $header = $this->base64UrlEncode(json_encode([
            'alg' => 'ES256',
            'typ' => 'JWT',
        ], JSON_UNESCAPED_SLASHES));

        $payload = $this->base64UrlEncode(json_encode([
            'aud' => $audience,
            'exp' => now()->addHours(12)->timestamp,
            'sub' => config('services.web_push.subject'),
        ], JSON_UNESCAPED_SLASHES));

        $input = "{$header}.{$payload}";
        $privateKeyPem = str_replace('\n', "\n", (string) config('services.web_push.private_key'));
        $privateKey = openssl_pkey_get_private($privateKeyPem);

        if (! $privateKey) {
            throw new RuntimeException('Invalid WEB_PUSH_VAPID_PRIVATE_KEY configuration.');
        }

        $signatureDer = '';
        $signed = openssl_sign($input, $signatureDer, $privateKey, OPENSSL_ALGO_SHA256);

        if (! $signed) {
            throw new RuntimeException('Failed to sign VAPID JWT.');
        }

        return "{$input}.".$this->base64UrlEncode($this->derToJose($signatureDer, 64));
    }

    private function derToJose(string $der, int $partLength): string
    {
        $offset = 0;

        if (ord($der[$offset++]) !== 0x30) {
            throw new RuntimeException('Invalid DER signature sequence.');
        }

        $this->readDerLength($der, $offset);

        if (ord($der[$offset++]) !== 0x02) {
            throw new RuntimeException('Invalid DER signature R marker.');
        }

        $rLength = $this->readDerLength($der, $offset);
        $r = substr($der, $offset, $rLength);
        $offset += $rLength;

        if (ord($der[$offset++]) !== 0x02) {
            throw new RuntimeException('Invalid DER signature S marker.');
        }

        $sLength = $this->readDerLength($der, $offset);
        $s = substr($der, $offset, $sLength);

        $r = ltrim($r, "\x00");
        $s = ltrim($s, "\x00");
        $r = str_pad($r, $partLength / 2, "\x00", STR_PAD_LEFT);
        $s = str_pad($s, $partLength / 2, "\x00", STR_PAD_LEFT);

        return $r.$s;
    }

    private function readDerLength(string $der, int &$offset): int
    {
        $length = ord($der[$offset++]);
        if (($length & 0x80) === 0) {
            return $length;
        }

        $numBytes = $length & 0x7F;
        $length = 0;
        for ($i = 0; $i < $numBytes; $i++) {
            $length = ($length << 8) | ord($der[$offset++]);
        }

        return $length;
    }

    private function rawEcPublicKeyToPem(string $rawPublicKey): string
    {
        $prefix = hex2bin('3059301306072A8648CE3D020106082A8648CE3D030107034200');
        $der = $prefix.$rawPublicKey;
        $pem = "-----BEGIN PUBLIC KEY-----\n";
        $pem .= chunk_split(base64_encode($der), 64, "\n");
        $pem .= "-----END PUBLIC KEY-----\n";

        return $pem;
    }

    private function hkdfExtract(string $salt, string $ikm): string
    {
        return hash_hmac('sha256', $ikm, $salt, true);
    }

    private function hkdfExpand(string $prk, string $info, int $length): string
    {
        $t = '';
        $okm = '';
        $counter = 1;

        while (strlen($okm) < $length) {
            $t = hash_hmac('sha256', $t.$info.chr($counter), $prk, true);
            $okm .= $t;
            $counter++;
        }

        return substr($okm, 0, $length);
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $data): string
    {
        $padded = strtr($data, '-_', '+/');
        $padding = strlen($padded) % 4;

        if ($padding > 0) {
            $padded .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode($padded, true);

        return is_string($decoded) ? $decoded : '';
    }
}
