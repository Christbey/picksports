<?php

namespace App\Console\Commands;

use App\Models\CBB\Team as CbbTeam;
use App\Models\CFB\Team as CfbTeam;
use App\Models\MLB\Team as MlbTeam;
use App\Models\NBA\Team as NbaTeam;
use App\Models\NFL\Team as NflTeam;
use App\Models\WCBB\Team as WcbbTeam;
use App\Models\WNBA\Team as WnbaTeam;
use App\Services\SportsAssetStorage;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;

class SyncTeamLogosCommand extends Command
{
    protected $signature = 'team-logos:sync {sport? : Optional sport key (nba, nfl, cfb, cbb, mlb, wnba, wcbb)}';

    protected $description = 'Mirror team logos into configured object storage and rewrite stored logo paths.';

    public function handle(SportsAssetStorage $sportsAssetStorage): int
    {
        $requestedSport = strtolower(trim((string) $this->argument('sport')));
        $sports = $requestedSport !== ''
            ? collect($this->sportMap())->only([$requestedSport])->all()
            : $this->sportMap();

        if ($sports === []) {
            $this->error('Unknown sport. Use one of: '.implode(', ', array_keys($this->sportMap())));

            return self::FAILURE;
        }

        foreach ($sports as $sport => $config) {
            $updated = $this->syncSport($sport, $config, $sportsAssetStorage);
            $this->info(strtoupper($sport).": updated {$updated} team logo records.");
        }

        return self::SUCCESS;
    }

    /**
     * @param  array{model:class-string<Model>,columns:list<string>}  $config
     */
    protected function syncSport(string $sport, array $config, SportsAssetStorage $sportsAssetStorage): int
    {
        $updated = 0;

        $config['model']::query()
            ->orderBy('id')
            ->chunkById(100, function ($teams) use ($sport, $config, $sportsAssetStorage, &$updated): void {
                foreach ($teams as $team) {
                    $dirty = false;
                    $teamIdentifier = $this->teamAssetIdentifier($team);

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
     * @return array<string, array{model:class-string<Model>,columns:list<string>}>
     */
    protected function sportMap(): array
    {
        return [
            'nba' => ['model' => NbaTeam::class, 'columns' => ['logo_url']],
            'nfl' => ['model' => NflTeam::class, 'columns' => ['logo_url']],
            'cfb' => ['model' => CfbTeam::class, 'columns' => ['logo', 'logo_url']],
            'cbb' => ['model' => CbbTeam::class, 'columns' => ['logo_url']],
            'mlb' => ['model' => MlbTeam::class, 'columns' => ['logo_url']],
            'wnba' => ['model' => WnbaTeam::class, 'columns' => ['logo', 'logo_url']],
            'wcbb' => ['model' => WcbbTeam::class, 'columns' => ['logo_url']],
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
}
