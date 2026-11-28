<?php

namespace App\Services\CBB;

use App\Models\CBB\Game;
use App\Services\Predictions\CanonicalPayloadHasher;
use App\Support\ScoreObservation;
use Illuminate\Database\Connection;
use Illuminate\Support\Collection;

/** An explicit observed absorbing state, not an inference from final status. */
final readonly class ObservedTerminalEpaContext
{
    public const POLICY = 'cbb-observed-terminal-v1';

    public const MAXIMUM_PLAYS = 4096;

    public const BASELINE_FIELDS = ['id', 'sport', 'season', 'source_season', 'state_key', 'expected_points', 'sample_size', 'created_at', 'updated_at'];

    public const GAME_FIELDS = ['id', 'espn_event_id', 'home_team_id', 'away_team_id', 'status', 'home_score', 'away_score', 'period', 'game_clock', 'season'];

    public const SOURCE_FIELDS = ['id', 'game_id', 'espn_play_id', 'sequence_number', 'period', 'clock', 'play_type', 'play_text', 'possession_team_id', 'home_score', 'away_score'];

    private function __construct(public int $gameId, public int $homeTeamId, public int $awayTeamId, public int $season,
        public int $terminalPlayId, public string $sourceHash, private string $playsHash, public array $baselineMap, public ?string $baselineSourceHash) {}

    public static function capture(Game $game, Collection $plays, array $baselineMap = [], ?string $baselineSourceHash = null): ?self
    {
        if ($game->isDirty(self::GAME_FIELDS) || $plays->contains(fn ($play) => $play->isDirty(self::SOURCE_FIELDS))) {
            return null;
        }
        $source = self::sourceHash($game, $plays, $baselineMap, $baselineSourceHash);
        $home = ScoreObservation::known($game->getRawOriginal('home_score'));
        $away = ScoreObservation::known($game->getRawOriginal('away_score'));
        $gameId = self::integer($game->getRawOriginal('id'));
        $homeTeam = self::integer($game->getRawOriginal('home_team_id'));
        $awayTeam = self::integer($game->getRawOriginal('away_team_id'));
        $season = self::integer($game->getRawOriginal('season'));
        $eventId = $game->getRawOriginal('espn_event_id');
        if ($game->getRawOriginal('status') !== 'STATUS_FINAL' || $home === null || $away === null || $home === $away
            || $gameId === null || $gameId <= 0 || $homeTeam === null || $homeTeam <= 0 || $awayTeam === null || $awayTeam <= 0
            || $homeTeam === $awayTeam || $season === null || $season <= 0
            || ! is_string($eventId) || trim($eventId) === '' || $plays->isEmpty()) {
            return null;
        }
        $last = $plays->last();
        $terminals = $plays->filter(fn ($play) => in_array($play->getRawOriginal('play_type'), ['End Game', 'End of Game'], true));
        $terminalId = self::integer($last->getRawOriginal('id'));
        $period = self::integer($last->getRawOriginal('period'));
        $gamePeriod = $game->getRawOriginal('period');
        $gameClock = $game->getRawOriginal('game_clock');
        if ($terminals->count() !== 1 || $terminalId === null || $terminalId <= 0
            || self::integer($terminals->first()->getRawOriginal('id')) !== $terminalId
            || $period === null || $period < 2 || ! in_array($last->getRawOriginal('clock'), ['0:00', '00:00'], true)
            || ScoreObservation::known($last->getRawOriginal('home_score')) !== $home || ScoreObservation::known($last->getRawOriginal('away_score')) !== $away
            || ($gamePeriod !== null && self::integer($gamePeriod) !== $period)
            || ($gameClock !== null && ! in_array($gameClock, ['0:00', '00:00'], true))) {
            return null;
        }
        $sequence = -1;
        foreach ($plays as $play) {
            $next = self::integer($play->getRawOriginal('sequence_number'));
            $playPeriod = self::integer($play->getRawOriginal('period'));
            $possession = $play->getRawOriginal('possession_team_id');
            $clock = $play->getRawOriginal('clock');
            if ($next === null || $next <= $sequence || self::integer($play->getRawOriginal('game_id')) !== $gameId
                || ($possession !== null && ! in_array(self::integer($possession), [$homeTeam, $awayTeam], true))
                || $playPeriod === null || $playPeriod < 1 || $playPeriod > $period
                || ScoreObservation::known($play->getRawOriginal('home_score')) === null || ScoreObservation::known($play->getRawOriginal('away_score')) === null
                || ! is_string($clock) || ! preg_match('/^(?:[0-9]|[01][0-9]|20):[0-5][0-9]$/D', $clock)
                || (int) explode(':', $clock)[0] > ($playPeriod > 2 ? 5 : 20)
                || ((int) explode(':', $clock)[0] === ($playPeriod > 2 ? 5 : 20) && substr($clock, -2) !== '00')) {
                return null;
            }
            $sequence = $next;
        }

        return new self($gameId, $homeTeam, $awayTeam, $season,
            $terminalId, $source, self::playHash($plays), array_map(fn ($value) => $value, $baselineMap), $baselineSourceHash);
    }

    private static function integer(mixed $value): ?int
    {
        if (! is_int($value) && (! is_string($value) || preg_match('/^(?:0|[1-9][0-9]*)$/D', $value) !== 1)) {
            return null;
        }
        $parsed = filter_var($value, FILTER_VALIDATE_INT);

        return $parsed === false || $parsed < 0 ? null : $parsed;
    }

    /** Fixed internal scalar projection; no caller-supplied SQL fragments. */
    public static function projection(Connection $connection, array $fields): array
    {
        if (array_diff($fields, [...self::GAME_FIELDS, ...self::SOURCE_FIELDS, ...self::BASELINE_FIELDS]) !== []) {
            throw new \RuntimeException('Unsupported CBB EPA projection.');
        }
        $columns = [];
        $oversized = [];
        foreach ($fields as $field) {
            $column = $connection->getQueryGrammar()->wrap($field);
            $limit = $field === 'play_text' ? 2048 : 128;
            $length = match ($connection->getDriverName()) {
                'mysql' => "OCTET_LENGTH($column)",
                'sqlite' => "LENGTH(CAST($column AS BLOB))",
                default => throw new \RuntimeException('CBB EPA source dialect is unsupported.'),
            };
            $oversized[] = "$length > $limit";
            $columns[] = $connection->raw("CASE WHEN $length <= $limit OR $column IS NULL THEN $column ELSE NULL END AS $column");
        }
        $columns[] = $connection->raw('CASE WHEN '.implode(' OR ', $oversized).' THEN 1 ELSE 0 END AS source_oversized');

        return $columns;
    }

    public function assertMatches(Game $game, Collection $plays, ?string $baselineSourceHash = null): void
    {
        if ($game->isDirty(self::GAME_FIELDS) || $plays->contains(fn ($play) => $play->isDirty(self::SOURCE_FIELDS))
            || ! hash_equals($this->sourceHash, self::sourceHash($game, $plays, $this->baselineMap, $baselineSourceHash))) {
            throw new \RuntimeException('CBB EPA terminal source changed during calculation.');
        }
    }

    public function assertCalculatorInputs(Collection $plays, int $home, int $away, ?int $season): void
    {
        if ($home !== $this->homeTeamId || $away !== $this->awayTeamId || $season !== $this->season
            || $plays->isEmpty() || self::integer($plays->last()->getRawOriginal('id')) !== $this->terminalPlayId
            || $plays->contains(fn ($play) => $play->isDirty(self::SOURCE_FIELDS) || self::integer($play->getRawOriginal('game_id')) !== $this->gameId)
            || ! hash_equals($this->playsHash, self::playHash($plays))) {
            throw new \RuntimeException('CBB EPA terminal context does not match calculator inputs.');
        }
    }

    private static function playHash(Collection $plays): string
    {
        if ($plays->count() > self::MAXIMUM_PLAYS) {
            throw new \RuntimeException('CBB EPA source exceeds its bounded policy.');
        }
        $state = hash_init('sha256');
        hash_update($state, self::POLICY."\0ordered-play-source-v1\0");
        $sourceBytes = 0;
        $hasher = app(CanonicalPayloadHasher::class);
        foreach ($plays as $play) {
            $row = [];
            foreach (self::SOURCE_FIELDS as $field) {
                $value = $play->getRawOriginal($field);
                $limit = $field === 'play_text' ? 2048 : 128;
                if ((! is_scalar($value) && $value !== null) || (is_string($value) && strlen($value) > $limit)) {
                    throw new \RuntimeException('CBB EPA source contains an unsupported field.');
                }
                $sourceBytes += is_string($value) ? strlen($value) : 16;
                if ($sourceBytes > 1048576) {
                    throw new \RuntimeException('CBB EPA current source exceeds its byte policy.');
                }
                $row[$field] = $value;
            }
            $json = $hasher->encode($row);
            hash_update($state, strlen($json).':'.$json);
        }

        return hash_final($state);
    }

    public static function sourceHash(Game $game, Collection $plays, array $baseline, ?string $baselineSourceHash): string
    {
        if (($baselineSourceHash !== null && preg_match('/^[a-f0-9]{64}$/D', $baselineSourceHash) !== 1) || ($baseline !== [] && $baselineSourceHash === null)) {
            throw new \RuntimeException('CBB EPA baseline source binding is unavailable.');
        }
        if (count($baseline) > 4096) {
            throw new \RuntimeException('CBB EPA baseline exceeds its bounded policy.');
        }
        foreach (self::GAME_FIELDS as $field) {
            $value = $game->getRawOriginal($field);
            if ((! is_scalar($value) && $value !== null) || (is_string($value) && strlen($value) > 128)) {
                throw new \RuntimeException('CBB EPA game source exceeds its scalar policy.');
            }
        }
        foreach ($baseline as $key => $value) {
            if (! is_string($key) || strlen($key) > 128 || (! is_float($value) && ! is_int($value)) || ! is_finite((float) $value)) {
                throw new \RuntimeException('CBB EPA baseline is unavailable.');
            }
        }

        return app(CanonicalPayloadHasher::class)->hash(['policy' => self::POLICY,
            'game' => array_intersect_key($game->getRawOriginal(), array_flip(self::GAME_FIELDS)),
            'plays_sha256' => self::playHash($plays), 'baseline' => $baseline, 'baseline_source_hash' => $baselineSourceHash]);
    }
}
