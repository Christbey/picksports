<?php

namespace App\Services\Auth;

use Illuminate\Validation\ValidationException;

class PasskeyService
{
    public function generateChallenge(): string
    {
        return $this->base64UrlEncode(random_bytes(32));
    }

    /**
     * @return array{type:string,challenge:string,origin:string}
     */
    public function validateClientData(
        string $encodedClientDataJson,
        string $expectedChallenge,
        string $expectedType,
        string $expectedOrigin,
    ): array {
        $decoded = $this->base64UrlDecode($encodedClientDataJson);

        $payload = json_decode($decoded, true);

        if (! is_array($payload)) {
            throw ValidationException::withMessages([
                'credential' => 'Invalid passkey client data payload.',
            ]);
        }

        if (($payload['type'] ?? null) !== $expectedType) {
            throw ValidationException::withMessages([
                'credential' => 'Unexpected passkey response type.',
            ]);
        }

        if (($payload['challenge'] ?? null) !== $expectedChallenge) {
            throw ValidationException::withMessages([
                'credential' => 'Passkey challenge mismatch.',
            ]);
        }

        if (($payload['origin'] ?? null) !== $expectedOrigin) {
            throw ValidationException::withMessages([
                'credential' => 'Passkey origin is not allowed.',
            ]);
        }

        return [
            'type' => (string) $payload['type'],
            'challenge' => (string) $payload['challenge'],
            'origin' => (string) $payload['origin'],
        ];
    }

    /**
     * @return array{signCount:int,flags:int,raw:string}
     */
    public function validateAuthenticatorData(
        string $encodedAuthenticatorData,
        string $expectedRpId,
        bool $requireUserVerification = false,
    ): array {
        $authenticatorData = $this->base64UrlDecode($encodedAuthenticatorData);

        if (strlen($authenticatorData) < 37) {
            throw ValidationException::withMessages([
                'credential' => 'Invalid authenticator data length.',
            ]);
        }

        $rpHash = substr($authenticatorData, 0, 32);
        $expectedRpHash = hash('sha256', $expectedRpId, true);

        if (! hash_equals($expectedRpHash, $rpHash)) {
            throw ValidationException::withMessages([
                'credential' => 'Passkey RP ID does not match this application.',
            ]);
        }

        $flags = ord($authenticatorData[32]);

        if (($flags & 0x01) !== 0x01) {
            throw ValidationException::withMessages([
                'credential' => 'Passkey user presence check failed.',
            ]);
        }

        if ($requireUserVerification && ($flags & 0x04) !== 0x04) {
            throw ValidationException::withMessages([
                'credential' => 'Passkey user verification is required.',
            ]);
        }

        $signCountBytes = substr($authenticatorData, 33, 4);
        $signCount = unpack('Ncount', $signCountBytes)['count'] ?? 0;

        return [
            'signCount' => (int) $signCount,
            'flags' => $flags,
            'raw' => $authenticatorData,
        ];
    }

    public function publicKeyPemFromSpki(string $encodedSpkiPublicKey): string
    {
        $spki = $this->base64UrlDecode($encodedSpkiPublicKey);

        $pem = "-----BEGIN PUBLIC KEY-----\n"
            .chunk_split(base64_encode($spki), 64, "\n")
            ."-----END PUBLIC KEY-----\n";

        if (! openssl_pkey_get_public($pem)) {
            throw ValidationException::withMessages([
                'credential' => 'Unsupported passkey public key.',
            ]);
        }

        return $pem;
    }

    /**
     * @return array{credentialId:string,publicKeyPem:string,algorithm:int,authenticatorData:string}
     */
    public function extractCredentialFromAttestationObject(string $encodedAttestationObject): array
    {
        $attestationObject = $this->base64UrlDecode($encodedAttestationObject);
        $offset = 0;
        $decoded = $this->decodeCborValue($attestationObject, $offset);

        if (! is_array($decoded) || ! isset($decoded['authData']) || ! is_string($decoded['authData'])) {
            throw ValidationException::withMessages([
                'credential' => 'Invalid passkey attestation object.',
            ]);
        }

        $authData = $decoded['authData'];

        if (strlen($authData) < 55) {
            throw ValidationException::withMessages([
                'credential' => 'Invalid passkey attestation data.',
            ]);
        }

        $flags = ord($authData[32]);

        if (($flags & 0x40) !== 0x40) {
            throw ValidationException::withMessages([
                'credential' => 'Passkey attestation is missing credential data.',
            ]);
        }

        $credentialIdLength = unpack('nlength', substr($authData, 53, 2))['length'] ?? 0;
        $credentialIdOffset = 55;
        $credentialId = substr($authData, $credentialIdOffset, $credentialIdLength);

        if ($credentialId === '' || strlen($credentialId) !== $credentialIdLength) {
            throw ValidationException::withMessages([
                'credential' => 'Passkey credential ID could not be read.',
            ]);
        }

        $coseOffset = $credentialIdOffset + $credentialIdLength;
        $publicKeyCoseBytes = substr($authData, $coseOffset);
        $coseParsedOffset = 0;
        $coseKey = $this->decodeCborValue($publicKeyCoseBytes, $coseParsedOffset);

        if (! is_array($coseKey)) {
            throw ValidationException::withMessages([
                'credential' => 'Invalid passkey public key format.',
            ]);
        }

        $algorithm = (int) ($coseKey[3] ?? 0);

        if ($algorithm !== -7) {
            throw ValidationException::withMessages([
                'credential' => 'Unsupported passkey algorithm from attestation.',
            ]);
        }

        $x = $coseKey[-2] ?? null;
        $y = $coseKey[-3] ?? null;

        if (! is_string($x) || ! is_string($y) || strlen($x) !== 32 || strlen($y) !== 32) {
            throw ValidationException::withMessages([
                'credential' => 'Invalid EC passkey key coordinates.',
            ]);
        }

        $uncompressedPoint = "\x04".$x.$y;
        $spkiDer = hex2bin('3059301306072A8648CE3D020106082A8648CE3D030107034200').$uncompressedPoint;

        $pem = "-----BEGIN PUBLIC KEY-----\n"
            .chunk_split(base64_encode($spkiDer), 64, "\n")
            ."-----END PUBLIC KEY-----\n";

        if (! openssl_pkey_get_public($pem)) {
            throw ValidationException::withMessages([
                'credential' => 'Passkey public key conversion failed.',
            ]);
        }

        return [
            'credentialId' => $this->base64UrlEncode($credentialId),
            'publicKeyPem' => $pem,
            'algorithm' => $algorithm,
            'authenticatorData' => $this->base64UrlEncode(substr($authData, 0, 37)),
        ];
    }

    public function verifyAssertionSignature(
        string $publicKeyPem,
        string $encodedAuthenticatorData,
        string $encodedClientDataJson,
        string $encodedSignature,
    ): bool {
        $authenticatorData = $this->base64UrlDecode($encodedAuthenticatorData);
        $clientDataJson = $this->base64UrlDecode($encodedClientDataJson);
        $signature = $this->base64UrlDecode($encodedSignature);

        $payload = $authenticatorData.hash('sha256', $clientDataJson, true);

        return openssl_verify($payload, $signature, $publicKeyPem, OPENSSL_ALGO_SHA256) === 1;
    }

    public function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    public function base64UrlDecode(string $value): string
    {
        $normalized = strtr($value, '-_', '+/');
        $padding = strlen($normalized) % 4;

        if ($padding > 0) {
            $normalized .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode($normalized, true);

        if ($decoded === false) {
            throw ValidationException::withMessages([
                'credential' => 'Invalid passkey payload encoding.',
            ]);
        }

        return $decoded;
    }

    /**
     * @return mixed
     */
    private function decodeCborValue(string $data, int &$offset)
    {
        if (! isset($data[$offset])) {
            throw ValidationException::withMessages([
                'credential' => 'Malformed passkey CBOR payload.',
            ]);
        }

        $initial = ord($data[$offset]);
        $offset++;

        $majorType = $initial >> 5;
        $additionalInfo = $initial & 0x1f;
        $length = $this->decodeCborLength($data, $offset, $additionalInfo);

        return match ($majorType) {
            0 => $length,
            1 => -1 - $length,
            2 => $this->readBytes($data, $offset, $length),
            3 => $this->readBytes($data, $offset, $length),
            4 => $this->decodeCborArray($data, $offset, $length),
            5 => $this->decodeCborMap($data, $offset, $length),
            default => throw ValidationException::withMessages([
                'credential' => 'Unsupported passkey CBOR type.',
            ]),
        };
    }

    private function decodeCborLength(string $data, int &$offset, int $additionalInfo): int
    {
        return match (true) {
            $additionalInfo < 24 => $additionalInfo,
            $additionalInfo === 24 => $this->readByte($data, $offset),
            $additionalInfo === 25 => unpack('nvalue', $this->readBytes($data, $offset, 2))['value'] ?? 0,
            $additionalInfo === 26 => unpack('Nvalue', $this->readBytes($data, $offset, 4))['value'] ?? 0,
            default => throw ValidationException::withMessages([
                'credential' => 'Unsupported passkey CBOR length encoding.',
            ]),
        };
    }

    /**
     * @return array<int, mixed>
     */
    private function decodeCborArray(string $data, int &$offset, int $length): array
    {
        $result = [];

        for ($i = 0; $i < $length; $i++) {
            $result[] = $this->decodeCborValue($data, $offset);
        }

        return $result;
    }

    /**
     * @return array<mixed, mixed>
     */
    private function decodeCborMap(string $data, int &$offset, int $length): array
    {
        $result = [];

        for ($i = 0; $i < $length; $i++) {
            $key = $this->decodeCborValue($data, $offset);
            $value = $this->decodeCborValue($data, $offset);
            $result[$key] = $value;
        }

        return $result;
    }

    private function readByte(string $data, int &$offset): int
    {
        if (! isset($data[$offset])) {
            throw ValidationException::withMessages([
                'credential' => 'Unexpected end of passkey CBOR payload.',
            ]);
        }

        $value = ord($data[$offset]);
        $offset++;

        return $value;
    }

    private function readBytes(string $data, int &$offset, int $length): string
    {
        $slice = substr($data, $offset, $length);

        if (strlen($slice) !== $length) {
            throw ValidationException::withMessages([
                'credential' => 'Unexpected end of passkey CBOR payload.',
            ]);
        }

        $offset += $length;

        return $slice;
    }
}
