<?php

namespace App\Actions\ESPN\MLB;

use App\Actions\ESPN\AbstractSyncGameDetails;
use App\Models\MLB\Game;
use Illuminate\Database\Eloquent\Model;

class SyncGameDetails extends AbstractSyncGameDetails
{
    protected const GAME_MODEL_CLASS = Game::class;

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

        if ($homeCompetitor && isset($homeCompetitor['score'])) {
            $updateData['home_score'] = $homeCompetitor['score'];
        }

        if ($awayCompetitor && isset($awayCompetitor['score'])) {
            $updateData['away_score'] = $awayCompetitor['score'];
        }

        if ($homeCompetitor && isset($homeCompetitor['linescores']) && is_array($homeCompetitor['linescores'])) {
            $homeLinescores = array_map(fn ($inning) => $inning['displayValue'] ?? '0', $homeCompetitor['linescores']);
            $updateData['home_linescores'] = json_encode($homeLinescores);
        }

        if ($awayCompetitor && isset($awayCompetitor['linescores']) && is_array($awayCompetitor['linescores'])) {
            $awayLinescores = array_map(fn ($inning) => $inning['displayValue'] ?? '0', $awayCompetitor['linescores']);
            $updateData['away_linescores'] = json_encode($awayLinescores);
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
}
