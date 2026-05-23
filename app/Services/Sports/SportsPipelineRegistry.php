<?php

namespace App\Services\Sports;

use Carbon\Carbon;

class SportsPipelineRegistry
{
    /**
     * @var array<int, string>
     */
    private const SUPPORTED_SPORTS = ['nba', 'nfl', 'mlb', 'cbb', 'wcbb', 'wnba', 'cfb'];

    /**
     * @var array<int, string>
     */
    private const SUPPORTED_MODES = ['sync', 'predict', 'full', 'live'];

    /**
     * @return array<int, string>
     */
    public function supportedSports(): array
    {
        return self::SUPPORTED_SPORTS;
    }

    /**
     * @return array<int, string>
     */
    public function supportedModes(): array
    {
        return self::SUPPORTED_MODES;
    }

    public function supportsSport(string $sport): bool
    {
        return in_array(strtolower($sport), self::SUPPORTED_SPORTS, true);
    }

    public function supportsMode(string $mode): bool
    {
        return in_array(strtolower($mode), self::SUPPORTED_MODES, true);
    }

    /**
     * @return array{reference_date: Carbon, season: int, week: int, current_year: int, fall_season_year: int}
     */
    public function context(?string $date = null, int|string|null $season = null, int|string|null $week = null): array
    {
        $referenceDate = $date ? Carbon::parse($date) : now();
        $currentYear = (int) $referenceDate->year;
        $fallSeasonYear = $referenceDate->month <= 2 ? $currentYear - 1 : $currentYear;

        $cfbRegularSeasonStart = $referenceDate->copy()
            ->setYear($fallSeasonYear)
            ->setMonth(8)
            ->setDay(24)
            ->startOfDay();

        return [
            'reference_date' => $referenceDate,
            'season' => $season !== null && $season !== '' ? (int) $season : $fallSeasonYear,
            'week' => $week !== null && $week !== ''
                ? (int) $week
                : ($referenceDate->lessThan($cfbRegularSeasonStart)
                    ? 1
                    : min($referenceDate->copy()->startOfDay()->diffInWeeks($cfbRegularSeasonStart) + 1, 15)),
            'current_year' => $currentYear,
            'fall_season_year' => $fallSeasonYear,
        ];
    }

    /**
     * @param  array{reference_date: Carbon, season: int, week: int, current_year: int, fall_season_year: int}  $context
     * @return array<int, array{label: string, command: string, arguments: array<string, mixed>}>
     */
    public function pipelineSteps(string $sport, string $mode, array $context): array
    {
        $sport = strtolower($sport);
        $mode = strtolower($mode);

        $syncSteps = $this->syncSteps($sport, $context);
        $predictSteps = $this->predictSteps($sport, $context);
        $liveSteps = $this->liveSteps($sport, $context);

        return match ($mode) {
            'sync' => $syncSteps,
            'predict' => $predictSteps,
            'live' => $liveSteps,
            'full' => array_values(array_merge($syncSteps, $predictSteps)),
            default => [],
        };
    }

    /**
     * @param  array{reference_date: Carbon, season: int, week: int, current_year: int, fall_season_year: int}|null  $context
     * @return array{command: string, arguments: array<string, mixed>}|null
     */
    public function healthcheckCommand(string $sport, string $checkType, ?array $context = null): ?array
    {
        $sport = strtolower($sport);
        $context ??= $this->context();

        return match ($checkType) {
            'heartbeat_sync' => $this->firstStepCommand($this->syncSteps($sport, $context)),
            'heartbeat_prediction_pipeline' => $this->predictionStepCommand($sport, $context, 'Generate predictions'),
            'heartbeat_model_pipeline' => $this->predictionStepCommand($sport, $context, 'Calculate Elo'),
            'heartbeat_odds' => $this->firstStepMatchingLabel($this->syncSteps($sport, $context), 'Sync odds'),
            'heartbeat_player_props' => $this->firstStepMatchingLabel($this->syncSteps($sport, $context), 'Sync player props'),
            'heartbeat_live_scoreboard' => $this->firstStepMatchingLabel($this->liveSteps($sport, $context), 'Live scoreboard sync'),
            default => null,
        };
    }

    /**
     * @param  array<int, array{label: string, command: string, arguments: array<string, mixed>}>  $steps
     * @return array{command: string, arguments: array<string, mixed>}|null
     */
    private function firstStepCommand(array $steps): ?array
    {
        if ($steps === []) {
            return null;
        }

        return [
            'command' => $steps[0]['command'],
            'arguments' => $steps[0]['arguments'],
        ];
    }

    /**
     * @param  array<int, array{label: string, command: string, arguments: array<string, mixed>}>  $steps
     * @return array{command: string, arguments: array<string, mixed>}|null
     */
    private function firstStepMatchingLabel(array $steps, string $label): ?array
    {
        foreach ($steps as $step) {
            if ($step['label'] !== $label) {
                continue;
            }

            return [
                'command' => $step['command'],
                'arguments' => $step['arguments'],
            ];
        }

        return null;
    }

    /**
     * @param  array{reference_date: Carbon, season: int, week: int, current_year: int, fall_season_year: int}  $context
     * @return array{command: string, arguments: array<string, mixed>}|null
     */
    private function predictionStepCommand(string $sport, array $context, string $label): ?array
    {
        return $this->firstStepMatchingLabel($this->predictSteps($sport, $context), $label);
    }

    /**
     * @param  array{reference_date: Carbon, season: int, week: int, current_year: int, fall_season_year: int}  $context
     * @return array<int, array{label: string, command: string, arguments: array<string, mixed>}>
     */
    private function syncSteps(string $sport, array $context): array
    {
        $referenceDate = $context['reference_date'];
        $season = $this->seasonForSport($sport, $context);
        $rangeEnd = $referenceDate->copy()->addDays(7)->format('Y-m-d');

        return match ($sport) {
            'nba' => [
                $this->step('Sync scoreboard window', 'espn:sync-nba-games-scoreboard', [
                    '--from-date' => $referenceDate->format('Y-m-d'),
                    '--to-date' => $rangeEnd,
                ]),
                $this->step('Sync game details', 'espn:sync-nba-game-details'),
                $this->step('Sync injuries', 'espn:sync-nba-injuries'),
                $this->step('Sync odds', 'nba:sync-odds'),
                $this->step('Sync player props', 'nba:sync-player-props'),
                $this->step('Sync futures odds', 'sports:sync-futures-odds', [
                    '--sport' => [$sport],
                    '--season' => $season,
                ]),
            ],
            'nfl' => [
                $this->step('Sync current week', 'espn:sync-nfl-current'),
                $this->step('Sync depth charts', 'espn:sync-nfl-depth-charts', ['--season' => $season]),
                $this->step('Sync game details', 'espn:sync-nfl-game-details'),
                $this->step('Sync injuries', 'espn:sync-nfl-injuries'),
                $this->step('Sync game weather', 'nfl:sync-game-weather', [
                    '--season' => $season,
                    '--days-back' => 0,
                    '--days-forward' => 7,
                    '--force' => true,
                ]),
                $this->step('Sync odds', 'nfl:sync-odds'),
                $this->step('Sync player props', 'nfl:sync-player-props'),
                $this->step('Sync futures odds', 'sports:sync-futures-odds', [
                    '--sport' => [$sport],
                    '--season' => $season,
                ]),
            ],
            'mlb' => [
                $this->step('Sync schedules', 'espn:sync-mlb-schedules', [
                    '--season' => $season,
                ]),
                $this->step('Sync game details', 'espn:sync-mlb-game-details'),
                $this->step('Sync injuries', 'espn:sync-mlb-injuries'),
                $this->step('Sync odds', 'mlb:sync-odds'),
                $this->step('Sync player props', 'mlb:sync-player-props'),
                $this->step('Sync futures odds', 'sports:sync-futures-odds', [
                    '--sport' => [$sport],
                    '--season' => $season,
                ]),
            ],
            'cbb' => [
                $this->step('Sync current window', 'espn:sync-cbb-current'),
                $this->step('Sync game details', 'espn:sync-cbb-game-details'),
                $this->step('Sync injuries', 'espn:sync-cbb-injuries'),
                $this->step('Sync odds', 'cbb:sync-odds'),
                $this->step('Sync player props', 'cbb:sync-player-props'),
                $this->step('Sync futures odds', 'sports:sync-futures-odds', [
                    '--sport' => [$sport],
                    '--season' => $season,
                ]),
            ],
            'wcbb' => [
                $this->step('Sync current window', 'espn:sync-wcbb-current'),
                $this->step('Sync game details', 'espn:sync-wcbb-game-details'),
                $this->step('Sync injuries', 'espn:sync-wcbb-injuries'),
                $this->step('Sync odds', 'wcbb:sync-odds'),
                $this->step('Sync futures odds', 'sports:sync-futures-odds', [
                    '--sport' => [$sport],
                    '--season' => $season,
                ]),
            ],
            'wnba' => [
                $this->step('Sync current week', 'espn:sync-wnba-current'),
                $this->step('Sync injuries', 'espn:sync-wnba-injuries'),
                $this->step('Sync odds', 'wnba:sync-odds'),
            ],
            'cfb' => [
                $this->step('Sync current week', 'espn:sync-cfb-current'),
                $this->step('Sync game details', 'espn:sync-cfb-game-details'),
                $this->step('Sync injuries', 'espn:sync-cfb-injuries'),
                $this->step('Sync odds', 'cfb:sync-odds'),
            ],
            default => [],
        };
    }

    /**
     * @param  array{reference_date: Carbon, season: int, week: int, current_year: int, fall_season_year: int}  $context
     * @return array<int, array{label: string, command: string, arguments: array<string, mixed>}>
     */
    private function predictSteps(string $sport, array $context): array
    {
        $season = $this->seasonForSport($sport, $context);

        return match ($sport) {
            'nba' => [
                $this->step('Grade predictions', 'nba:grade-predictions', ['--season' => $season]),
                $this->step('Calculate Elo', 'nba:calculate-elo', ['--season' => $season]),
                $this->step('Calculate team metrics', 'nba:calculate-team-metrics', ['--season' => $season]),
                $this->step('Generate predictions', 'nba:generate-predictions', ['--season' => $season]),
                $this->step('Generate playoff forecast', 'nba:generate-playoff-forecast', ['--season' => $season]),
            ],
            'nfl' => [
                $this->step('Grade predictions', 'nfl:grade-predictions', ['--season' => $season]),
                $this->step('Calculate Elo', 'nfl:calculate-elo', ['--season' => $season]),
                $this->step('Calculate team metrics', 'nfl:calculate-team-metrics', ['--season' => $season]),
                $this->step('Generate predictions', 'nfl:generate-predictions', ['--season' => $season]),
            ],
            'mlb' => [
                $this->step('Grade predictions', 'mlb:grade-predictions', ['--season' => $season]),
                $this->step('Calculate Elo', 'mlb:calculate-elo', ['--season' => $season]),
                $this->step('Calculate team metrics', 'mlb:calculate-team-metrics', ['--season' => $season]),
                $this->step('Generate predictions', 'mlb:generate-predictions', ['--season' => $season]),
                $this->step('Generate playoff forecast', 'mlb:generate-playoff-forecast', ['--season' => $season]),
            ],
            'cbb' => [
                $this->step('Grade predictions', 'cbb:grade-predictions', ['--season' => $season]),
                $this->step('Calculate Elo', 'cbb:calculate-elo', ['--season' => $season]),
                $this->step('Calculate team metrics', 'cbb:calculate-team-metrics', ['--season' => $season]),
                $this->step('Generate predictions', 'cbb:generate-predictions', ['--season' => $season]),
                $this->step('Generate tournament forecast', 'cbb:generate-tournament-forecast', ['--season' => $season]),
                $this->step('Recalculate tournament outlook', 'cbb:recalculate-tournament-outlook', [
                    'season' => $season,
                    '--source' => 'manual-pipeline',
                ]),
            ],
            'wcbb' => [
                $this->step('Grade predictions', 'wcbb:grade-predictions', ['--season' => $season]),
                $this->step('Calculate Elo', 'wcbb:calculate-elo', ['--season' => $season]),
                $this->step('Calculate team metrics', 'wcbb:calculate-team-metrics', ['--season' => $season]),
                $this->step('Generate predictions', 'wcbb:generate-predictions', ['--season' => $season]),
                $this->step('Generate tournament forecast', 'wcbb:generate-tournament-forecast', ['--season' => $season]),
            ],
            'wnba' => [
                $this->step('Grade predictions', 'wnba:grade-predictions', ['--season' => $season]),
                $this->step('Calculate Elo', 'wnba:calculate-elo', ['--season' => $season]),
                $this->step('Calculate team metrics', 'wnba:calculate-team-metrics', ['--season' => $season]),
                $this->step('Generate predictions', 'wnba:generate-predictions', ['--season' => $season]),
            ],
            'cfb' => [
                $this->step('Grade predictions', 'cfb:grade-predictions', ['--season' => $season]),
                $this->step('Calculate Elo', 'cfb:calculate-elo', ['--season' => $season]),
                $this->step('Import FPI', 'cfb:import-fpi', [
                    '--season' => $season,
                    '--week' => $context['week'],
                ]),
                $this->step('Calculate team metrics', 'cfb:calculate-team-metrics', ['--season' => $season]),
                $this->step('Generate predictions', 'cfb:generate-predictions', ['--season' => $season]),
            ],
            default => [],
        };
    }

    /**
     * @param  array{reference_date: Carbon, season: int, week: int, current_year: int, fall_season_year: int}  $context
     * @return array<int, array{label: string, command: string, arguments: array<string, mixed>}>
     */
    private function liveSteps(string $sport, array $context): array
    {
        $scoreboardArguments = [
            'date' => $context['reference_date']->format('Ymd'),
        ];

        return match ($sport) {
            'nba' => [
                $this->step('Live scoreboard sync', 'espn:sync-nba-games-scoreboard', $scoreboardArguments),
                $this->step('Sync game details', 'espn:sync-nba-game-details'),
                $this->step('Sync injuries', 'espn:sync-nba-injuries'),
            ],
            'nfl' => [
                $this->step('Live scoreboard sync', 'espn:sync-nfl-games-scoreboard', $scoreboardArguments),
                $this->step('Sync game details', 'espn:sync-nfl-game-details'),
                $this->step('Sync injuries', 'espn:sync-nfl-injuries'),
            ],
            'mlb' => [
                $this->step('Live scoreboard sync', 'espn:sync-mlb-games-scoreboard', $scoreboardArguments),
                $this->step('Sync game details', 'espn:sync-mlb-game-details'),
                $this->step('Sync injuries', 'espn:sync-mlb-injuries'),
            ],
            'cbb' => [
                $this->step('Live scoreboard sync', 'espn:sync-cbb-games-scoreboard', $scoreboardArguments),
                $this->step('Sync game details', 'espn:sync-cbb-game-details'),
                $this->step('Sync injuries', 'espn:sync-cbb-injuries'),
            ],
            'wcbb' => [
                $this->step('Live scoreboard sync', 'espn:sync-wcbb-games-scoreboard', $scoreboardArguments),
                $this->step('Sync game details', 'espn:sync-wcbb-game-details'),
                $this->step('Sync injuries', 'espn:sync-wcbb-injuries'),
            ],
            'wnba' => [
                $this->step('Live scoreboard sync', 'espn:sync-wnba-games-scoreboard', $scoreboardArguments),
                $this->step('Sync injuries', 'espn:sync-wnba-injuries'),
            ],
            'cfb' => [
                $this->step('Live scoreboard sync', 'espn:sync-cfb-games-scoreboard', $scoreboardArguments),
                $this->step('Sync game details', 'espn:sync-cfb-game-details'),
                $this->step('Sync injuries', 'espn:sync-cfb-injuries'),
            ],
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{label: string, command: string, arguments: array<string, mixed>}
     */
    private function step(string $label, string $command, array $arguments = []): array
    {
        return [
            'label' => $label,
            'command' => $command,
            'arguments' => $arguments,
        ];
    }

    /**
     * @param  array{reference_date: Carbon, season: int, week: int, current_year: int, fall_season_year: int}  $context
     */
    private function seasonForSport(string $sport, array $context): int
    {
        return in_array($sport, ['mlb', 'wnba'], true)
            ? $context['current_year']
            : $context['fall_season_year'];
    }
}
