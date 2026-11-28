<?php

namespace App\Services\WebPush;

use RuntimeException;

class PushHttpTransport
{
    public function __construct(private readonly PushEndpointPolicy $endpoints) {}

    /** @param list<string> $headers @return array{status:int,body:string} */
    public function post(string $endpoint, string $body, array $headers): array
    {
        $target = $this->endpoints->resolve($endpoint);
        $ip = str_contains($target['ip'], ':') ? '['.$target['ip'].']' : $target['ip'];
        $responseBody = '';
        $tooLarge = false;
        $protocols = defined('CURLOPT_PROTOCOLS_STR') && defined('CURLOPT_REDIR_PROTOCOLS_STR')
            ? [constant('CURLOPT_PROTOCOLS_STR') => 'HTTPS', constant('CURLOPT_REDIR_PROTOCOLS_STR') => 'HTTPS']
            : [CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS];
        $options = $protocols + [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROXY => '',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_RESOLVE => [$target['host'].':443:'.$ip],
            CURLOPT_WRITEFUNCTION => function ($handle, string $chunk) use (&$responseBody, &$tooLarge): int {
                if (strlen($responseBody) + strlen($chunk) > 65536) {
                    $tooLarge = true;

                    return 0;
                }
                $responseBody .= $chunk;

                return strlen($chunk);
            },
        ];
        // Match cURL's URL hostname exactly to the pinned resolver entry while
        // preserving the opaque path/query bytes used by the provider.
        $url = preg_replace('~\Ahttps://[^/?#]+~i', 'https://'.$target['host'], $endpoint);
        $status = $this->execute($url, $options);
        if ($tooLarge) {
            throw new RuntimeException('Push provider response exceeded the size limit.');
        }

        return ['status' => $status, 'body' => $responseBody];
    }

    /** Isolated wire boundary, overridable in tests without touching DNS or the network. @param array<int,mixed> $options */
    protected function execute(string $endpoint, array $options): int
    {
        $handle = curl_init($endpoint);
        if ($handle === false) {
            throw new RuntimeException('Unable to initialize push transport.');
        }
        try {
            if (! curl_setopt_array($handle, $options) || curl_exec($handle) === false) {
                // cURL errors can contain opaque endpoint tokens; do not expose them.
                throw new RuntimeException('Push provider request failed.');
            }

            return (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        } finally {
            curl_close($handle);
        }
    }
}
