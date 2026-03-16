<?php

namespace App\Console\Commands;

use App\Models\CBB\Player as CbbPlayer;
use App\Models\CBB\Team as CbbTeam;
use App\Models\CFB\Player as CfbPlayer;
use App\Models\CFB\Team as CfbTeam;
use App\Models\MLB\Player as MlbPlayer;
use App\Models\MLB\Team as MlbTeam;
use App\Models\NBA\Player as NbaPlayer;
use App\Models\NBA\Team as NbaTeam;
use App\Models\NFL\Player as NflPlayer;
use App\Models\NFL\Team as NflTeam;
use App\Models\WCBB\Player as WcbbPlayer;
use App\Models\WCBB\Team as WcbbTeam;
use App\Models\WNBA\Player as WnbaPlayer;
use App\Models\WNBA\Team as WnbaTeam;
use App\Services\SportsAssetStorage;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;

class SyncSportsAssetsCommand extends Command
{
    protected $signature = 'sports-assets:sync {sport? : Optional sport key (nba, nfl, cfb, cbb, mlb, wnba, wcbb)} {--type=all : Asset type to sync (all, teams, players)}';

    protected $description = 'Mirror team logos and player headshots into configured object storage and rewrite stored asset paths.';

    public function handle(SportsAssetStorage $sportsAssetStorage): int
    {
        $requestedSport = strtolower(trim((string) $this->argument('sport')));
        $type = strtolower(trim((string) $this->option('type')));
        $sports = $requestedSport !== ''
            ? collect($this->sportMap())->only([$requestedSport])->all()
            : $this->sportMap();

        if ($sports === []) {
            $this->error('Unknown sport. Use one of: '.implode(', ', array_keys($this->sportMap())));

            return self::FAILURE;
        }

        if (! in_array($type, ['all', 'teams', 'players'], true)) {
            $this->error('Unknown type. Use one of: all, teams, players.');

            return self::FAILURE;
        }

        foreach ($sports as $sport => $config) {
            $teamUpdates = in_array($type, ['all', 'teams'], true)
                ? $this->syncTeams($sport, $config['team'], $sportsAssetStorage)
                : 0;
            $playerUpdates = in_array($type, ['all', 'players'], true)
                ? $this->syncPlayers($sport, $config['player'], $sportsAssetStorage)
                : 0;

            $this->info(strtoupper($sport).": updated {$teamUpdates} teams and {$playerUpdates} players.");
        }

        return self::SUCCESS;
    }

    /**
     * @param  array{model:class-string<Model>,columns:list<string>}  $config
     */
    protected function syncTeams(string $sport, array $config, SportsAssetStorage $sportsAssetStorage): int
    {
        $updated = 0;

        $config['model']::query()
            ->orderBy('id')
            ->chunkById(100, function ($teams) use ($sport, $config, $sportsAssetStorage, &$updated): void {
                foreach ($teams as $team) {
                    $teamIdentifier = $this->teamAssetIdentifier($team);
                    $dirty = false;

                    foreach ($config['columns'] as $column) {
                        $current = $team->getRawOriginal($column);
                        $mirrored = $sportsAssetStorage->mirrorTeamLogo($current, $sport, $teamIdentifier);

                        if ($mirrored !== null && $mirrored !== $current) {
                            $team->{$column} = $mirrored;
                            $dirty = true;
                        }
                    }

                    if ($dirty) {
                        $team->save();
                        $updated++;
                    }
                }
            });

        return $updated;
    }

    /**
     * @param  array{model:class-string<Model>,column:string,team_relation:string}  $config
     */
    protected function syncPlayers(string $sport, array $config, SportsAssetStorage $sportsAssetStorage): int
    {
        $updated = 0;

        $config['model']::query()
            ->with($config['team_relation'])
            ->orderBy('id')
            ->chunkById(100, function ($players) use ($sport, $config, $sportsAssetStorage, &$updated): void {
                foreach ($players as $player) {
                    $current = $player->getRawOriginal($config['column']);
                    $team = $player->{$config['team_relation']};
                    $teamIdentifier = $team ? $this->teamAssetIdentifier($team) : ((string) ($player->team_id ?: 'unknown-team'));
                    $playerIdentifier = $this->playerAssetIdentifier($player);
                    $mirrored = $sportsAssetStorage->mirrorPlayerHeadshot($current, $sport, $teamIdentifier, $playerIdentifier);

                    if ($mirrored !== null && $mirrored !== $current) {
                        $player->{$config['column']} = $mirrored;
                        $player->save();
                        $updated++;
                    }
                }
            });

        return $updated;
    }

    /**
     * @return array<string, array{
     *   team: array{model:class-string<Model>,columns:list<string>},
     *   player: array{model:class-string<Model>,column:string,team_relation:string}
     * }>
     */
    protected function sportMap(): array
    {
        return [
            'nba' => [
                'team' => ['model' => NbaTeam::class, 'columns' => ['logo_url']],
                'player' => ['model' => NbaPlayer::class, 'column' => 'headshot_url', 'team_relation' => 'team'],
            ],
            'nfl' => [
                'team' => ['model' => NflTeam::class, 'columns' => ['logo_url']],
                'player' => ['model' => NflPlayer::class, 'column' => 'headshot_url', 'team_relation' => 'team'],
            ],
            'cfb' => [
                'team' => ['model' => CfbTeam::class, 'columns' => ['logo', 'logo_url']],
                'player' => ['model' => CfbPlayer::class, 'column' => 'headshot_url', 'team_relation' => 'team'],
            ],
            'cbb' => [
                'team' => ['model' => CbbTeam::class, 'columns' => ['logo_url']],
                'player' => ['model' => CbbPlayer::class, 'column' => 'headshot_url', 'team_relation' => 'team'],
            ],
            'mlb' => [
                'team' => ['model' => MlbTeam::class, 'columns' => ['logo_url']],
                'player' => ['model' => MlbPlayer::class, 'column' => 'headshot_url', 'team_relation' => 'team'],
            ],
            'wnba' => [
                'team' => ['model' => WnbaTeam::class, 'columns' => ['logo', 'logo_url']],
                'player' => ['model' => WnbaPlayer::class, 'column' => 'headshot_url', 'team_relation' => 'team'],
            ],
            'wcbb' => [
                'team' => ['model' => WcbbTeam::class, 'columns' => ['logo_url']],
                'player' => ['model' => WcbbPlayer::class, 'column' => 'headshot_url', 'team_relation' => 'team'],
            ],
        ];
    }

    protected function teamAssetIdentifier(Model $team): string
    {
        $name = trim(implode(' ', array_filter([
            $team->getRawOriginal('location') ?? $team->getRawOriginal('school') ?? null,
            $team->getRawOriginal('name') ?? $team->getRawOriginal('mascot') ?? null,
        ])));
        $id = (string) ($team->espn_id ?: $team->id);

        return $name !== '' ? "{$name}-{$id}" : $id;
    }

    protected function playerAssetIdentifier(Model $player): string
    {
        $name = trim((string) (
            $player->getRawOriginal('full_name')
            ?? $player->getRawOriginal('display_name')
            ?? $player->getRawOriginal('name')
            ?? trim(implode(' ', array_filter([
                $player->getRawOriginal('first_name') ?? null,
                $player->getRawOriginal('last_name') ?? null,
            ])))
        ));
        $id = (string) ($player->espn_id ?: $player->id);

        return $name !== '' ? "{$name}-{$id}" : $id;
    }
}
