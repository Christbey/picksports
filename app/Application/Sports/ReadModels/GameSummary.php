<?php

namespace App\Application\Sports\ReadModels;

final readonly class GameSummary
{
    /**
     * @param  array<int, mixed>|null  $homeLinescores
     * @param  array<int, mixed>|null  $awayLinescores
     * @param  array<int, mixed>|null  $broadcastNetworks
     * @param  array<int, array<string, mixed>>  $homeStartingPitcherCandidates
     * @param  array<int, array<string, mixed>>  $awayStartingPitcherCandidates
     * @param  array<string, mixed>|null  $pitcherProjectionMetadata
     * @param  array<string, mixed>|null  $startingPitcherConfirmationMetadata
     * @param  array<string, mixed>|null  $matchupContext
     * @param  array<string, mixed>|null  $homeStartingPitcher
     * @param  array<string, mixed>|null  $awayStartingPitcher
     * @param  array<string, mixed>|null  $homeStartingPitcherForecast
     * @param  array<string, mixed>|null  $awayStartingPitcherForecast
     */
    public function __construct(
        public int|string $id,
        public ?string $sportEventId,
        public string $sport,
        public ?string $espnId,
        public ?string $espnEventId,
        public ?string $espnUid,
        public ?int $season,
        public int|string|null $seasonType,
        public ?int $week,
        public int|string|null $postseasonRound,
        public ?string $name,
        public ?string $shortName,
        public ?string $gameDate,
        public ?string $gameTime,
        public ?string $venue,
        public ?string $venueCity,
        public ?string $venueState,
        public ?int $attendance,
        public ?string $status,
        public ?int $period,
        public ?string $gameClock,
        public ?int $homeTeamId,
        public ?int $awayTeamId,
        public int|float|null $homeScore,
        public int|float|null $awayScore,
        public ?array $homeLinescores,
        public ?array $awayLinescores,
        public ?array $broadcastNetworks,
        public ?int $inning,
        public ?string $inningHalf,
        public ?int $balls,
        public ?int $strikes,
        public ?int $outs,
        public ?string $probableHomePitcherEspnId,
        public ?string $probableAwayPitcherEspnId,
        public ?string $actualHomePitcherEspnId,
        public ?string $actualAwayPitcherEspnId,
        public ?string $projectedHomePitcherEspnId,
        public ?string $projectedAwayPitcherEspnId,
        public ?string $homeStartingPitcherSource,
        public ?string $awayStartingPitcherSource,
        public ?float $homeStartingPitcherConfidence,
        public ?float $awayStartingPitcherConfidence,
        public array $homeStartingPitcherCandidates,
        public array $awayStartingPitcherCandidates,
        public ?float $homeExpectedStartingPitcherRating,
        public ?float $awayExpectedStartingPitcherRating,
        public ?float $homeStartingPitcherUncertainty,
        public ?float $awayStartingPitcherUncertainty,
        public ?array $pitcherProjectionMetadata,
        public ?string $pitcherProjectionGeneratedAt,
        public ?array $startingPitcherConfirmationMetadata,
        public ?string $startingPitchersConfirmedAt,
        public bool $isNcaaTournament,
        public int|string|null $tournamentId,
        public ?string $tournamentNote,
        public int|string|null $tournamentRound,
        public ?string $tournamentRegion,
        public ?int $homeSeed,
        public ?int $awaySeed,
        public ?int $playInTargetSeed,
        public ?array $matchupContext,
        public ?TeamSummary $homeTeam,
        public ?TeamSummary $awayTeam,
        public ?array $homeStartingPitcher,
        public ?array $awayStartingPitcher,
        public ?array $homeStartingPitcherForecast,
        public ?array $awayStartingPitcherForecast,
        public ?PredictionSummary $prediction,
        public ?string $completedAt,
        public ?string $updatedAt,
    ) {}
}
