<?php

namespace App\Actions\ESPN\MLB;

use App\Actions\ESPN\AbstractSyncGameDetails;
use App\Actions\MLB\ReconcileGameScoreFromTeamStats;
use App\Models\MLB\Game;
use App\Services\MLB\MlbStartingPitcherForecastService;
use App\Support\MLB\MlbGamePhase;
use App\Support\MLB\MlbLineScores;
use Illuminate\Database\Eloquent\Model;

class SyncGameDetails extends AbstractSyncGameDetails
{
    protected const GAME_MODEL_CLASS = Game::class;

    public function execute(string $eventId): array
    {
        $result = parent::execute($eventId);

        $game = Game::query()->where('espn_event_id', $eventId)->first();

        if ($game) {
            $result['starting_pitcher_forecasts_graded'] = app(MlbStartingPitcherForecastService::class)
                ->confirmGame($game->fresh());
            $result['score_reconciliation'] = app(ReconcileGameScoreFromTeamStats::class)->execute($game->fresh());
        }

        return $result;
    }

    protected function includeGameUpdatedFlag(): bool
    {
        return true;
    }

    /**
     * @param  array<string, mixed>  $gameData
     */
    protected function updateGame(array $gameData, Model $game): bool
    {
        if (! $game instanceof Game) {
            return false;
        }

        $competition = $gameData['header']['competitions'][0] ?? null;

        if (! $competition) {
            return true;
        }

        $updateData = [];
        $wasFinal = MlbGamePhase::isFinal($game);

        if (isset($competition['status']['type']['name'])) {
            $updateData['status'] = $competition['status']['type']['name'];
        }

        if (isset($competition['status']['period']) && is_numeric($competition['status']['period'])) {
            $updateData['inning'] = (int) $competition['status']['period'];
        }

        $shortDetail = (string) ($competition['status']['type']['shortDetail'] ?? '');
        $displayClock = (string) ($competition['status']['displayClock'] ?? '');
        $inningHalf = $this->extractInningHalf($shortDetail, $displayClock);

        if ($inningHalf !== null) {
            $updateData['inning_half'] = $inningHalf;
        }

        $situation = is_array($competition['situation'] ?? null)
            ? $competition['situation']
            : (is_array($gameData['situation'] ?? null) ? $gameData['situation'] : null);

        if (is_array($situation)) {
            foreach (['balls', 'strikes', 'outs'] as $field) {
                if (isset($situation[$field]) && is_numeric($situation[$field])) {
                    $updateData[$field] = (int) $situation[$field];
                }
            }
        }

        $homeCompetitor = null;
        $awayCompetitor = null;

        foreach ($competition['competitors'] ?? [] as $competitor) {
            if ($competitor['homeAway'] === 'home') {
                $homeCompetitor = $competitor;
            } elseif ($competitor['homeAway'] === 'away') {
                $awayCompetitor = $competitor;
            }
        }

        if ($homeCompetitor && isset($homeCompetitor['score']) && $this->shouldUpdateScore($game->home_score, $homeCompetitor['score'], $wasFinal)) {
            $updateData['home_score'] = $homeCompetitor['score'];
        }

        if ($awayCompetitor && isset($awayCompetitor['score']) && $this->shouldUpdateScore($game->away_score, $awayCompetitor['score'], $wasFinal)) {
            $updateData['away_score'] = $awayCompetitor['score'];
        }

        if ($homeCompetitor && isset($homeCompetitor['linescores']) && is_array($homeCompetitor['linescores'])) {
            $homeLinescores = MlbLineScores::normalize($homeCompetitor['linescores']);
            $updateData['home_linescores'] = $homeLinescores;
        }

        if ($awayCompetitor && isset($awayCompetitor['linescores']) && is_array($awayCompetitor['linescores'])) {
            $awayLinescores = MlbLineScores::normalize($awayCompetitor['linescores']);
            $updateData['away_linescores'] = $awayLinescores;
        }

        if ($homeCompetitor) {
            $probablePitcherEspnId = $this->probablePitcherEspnId($homeCompetitor);
            if ($probablePitcherEspnId !== null) {
                $updateData['probable_home_pitcher_espn_id'] = $probablePitcherEspnId;
            }
        }

        if ($awayCompetitor) {
            $probablePitcherEspnId = $this->probablePitcherEspnId($awayCompetitor);
            if ($probablePitcherEspnId !== null) {
                $updateData['probable_away_pitcher_espn_id'] = $probablePitcherEspnId;
            }
        }

        $confirmedStarters = $this->confirmedStartingPitchers($gameData, $competition);
        if ($confirmedStarters !== []) {
            $confirmedAt = now();
            $metadata = is_array($game->starting_pitcher_confirmation_metadata)
                ? $game->starting_pitcher_confirmation_metadata
                : [];

            foreach ($confirmedStarters as $side => $pitcherEspnId) {
                $updateData["actual_{$side}_pitcher_espn_id"] = $pitcherEspnId;
                $metadata[$side] = [
                    'pitcher_espn_id' => $pitcherEspnId,
                    'source' => 'espn_boxscore',
                    'confirmed_at' => $confirmedAt->toIso8601String(),
                ];
            }

            $updateData['starting_pitcher_confirmation_metadata'] = $metadata;
            $updateData['starting_pitchers_confirmed_at'] = $confirmedAt;
        }

        if ($homeCompetitor) {
            if (isset($homeCompetitor['hits'])) {
                $updateData['home_hits'] = $homeCompetitor['hits'];
            }
            if (isset($homeCompetitor['errors'])) {
                $updateData['home_errors'] = $homeCompetitor['errors'];
            }
        }

        if ($awayCompetitor) {
            if (isset($awayCompetitor['hits'])) {
                $updateData['away_hits'] = $awayCompetitor['hits'];
            }
            if (isset($awayCompetitor['errors'])) {
                $updateData['away_errors'] = $awayCompetitor['errors'];
            }
        }

        if (! empty($updateData)) {
            $game->update($updateData);
        }

        return true;
    }

    private function shouldUpdateScore(mixed $existingScore, mixed $incomingScore, bool $wasFinal): bool
    {
        if (! is_numeric($incomingScore)) {
            return false;
        }

        if (! $wasFinal || ! is_numeric($existingScore)) {
            return true;
        }

        return (int) $existingScore === (int) $incomingScore;
    }

    private function extractInningHalf(string $shortDetail, string $displayClock): ?string
    {
        $detail = strtolower(trim($shortDetail.' '.$displayClock));

        if ($detail === '') {
            return null;
        }

        if (str_contains($detail, 'top')) {
            return 'top';
        }

        if (str_contains($detail, 'bottom') || str_contains($detail, 'bot')) {
            return 'bottom';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $competitor
     */
    private function probablePitcherEspnId(array $competitor): ?string
    {
        $probables = $competitor['probables'] ?? null;

        if (! is_array($probables)) {
            return null;
        }

        foreach ($probables as $probable) {
            $playerId = data_get($probable, 'playerId');
            if (is_scalar($playerId) && (string) $playerId !== '') {
                return (string) $playerId;
            }

            $athleteId = data_get($probable, 'athlete.id');
            if (is_scalar($athleteId) && (string) $athleteId !== '') {
                return (string) $athleteId;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $gameData
     * @param  array<string, mixed>  $competition
     * @return array<string, string>
     */
    private function confirmedStartingPitchers(array $gameData, array $competition): array
    {
        $sideByTeamId = [];
        $sideByAbbreviation = [];

        foreach ($competition['competitors'] ?? [] as $competitor) {
            $side = $competitor['homeAway'] ?? null;
            if (! in_array($side, ['home', 'away'], true)) {
                continue;
            }

            $teamId = data_get($competitor, 'team.id');
            if (is_scalar($teamId) && (string) $teamId !== '') {
                $sideByTeamId[(string) $teamId] = $side;
            }

            $abbreviation = strtoupper(trim((string) data_get($competitor, 'team.abbreviation')));
            if ($abbreviation !== '') {
                $sideByAbbreviation[$abbreviation] = $side;
            }
        }

        $confirmed = [];

        foreach (data_get($gameData, 'boxscore.players', []) as $teamData) {
            $teamId = trim((string) data_get($teamData, 'team.id'));
            $abbreviation = strtoupper(trim((string) data_get($teamData, 'team.abbreviation')));
            $side = $sideByTeamId[$teamId] ?? $sideByAbbreviation[$abbreviation] ?? null;

            if (! in_array($side, ['home', 'away'], true)) {
                continue;
            }

            foreach ($teamData['statistics'] ?? [] as $section) {
                if (strtolower((string) ($section['type'] ?? '')) !== 'pitching') {
                    continue;
                }

                foreach ($section['athletes'] ?? [] as $athleteData) {
                    if (($athleteData['starter'] ?? false) !== true) {
                        continue;
                    }

                    $pitcherEspnId = data_get($athleteData, 'athlete.id');
                    if (is_scalar($pitcherEspnId) && (string) $pitcherEspnId !== '') {
                        $confirmed[$side] = (string) $pitcherEspnId;
                        break 2;
                    }
                }
            }
        }

        return $confirmed;
    }
}
