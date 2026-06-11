<?php

namespace App\Services;

use App\Models\CommandHeartbeat;
use Illuminate\Support\Str;

class CommandHeartbeatService
{
    private const SOURCE_MAX_LENGTH = 20;

    public function recordSuccess(string $command, ?string $sport = null, string $source = 'schedule', array $metadata = []): void
    {
        $this->record(
            command: $command,
            status: 'success',
            sport: $sport,
            source: $source,
            metadata: $metadata
        );
    }

    public function recordFailure(string $command, ?string $sport = null, string $source = 'schedule', ?string $error = null, array $metadata = []): void
    {
        $this->record(
            command: $command,
            status: 'failure',
            sport: $sport,
            source: $source,
            error: $error,
            metadata: $metadata
        );
    }

    public function inferSportFromCommand(string $command): ?string
    {
        if (preg_match('/\b(mlb|nba|nfl|cbb|cfb|wcbb|wnba)\b/i', $command, $matches) !== 1) {
            return null;
        }

        return strtolower($matches[1]);
    }

    private function record(
        string $command,
        string $status,
        ?string $sport = null,
        string $source = 'schedule',
        ?string $error = null,
        array $metadata = []
    ): void {
        CommandHeartbeat::create([
            'sport' => $sport ?? $this->inferSportFromCommand($command),
            'command' => $command,
            'status' => $status,
            'source' => Str::substr($source, 0, self::SOURCE_MAX_LENGTH),
            'error' => $error,
            'metadata' => $metadata,
            'ran_at' => now(),
        ]);
    }
}
