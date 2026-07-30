<?php

namespace App\Console\Commands\Sports;

use App\Models\CBB\Game as CbbGame;
use App\Models\CFB\Game as CfbGame;
use App\Models\GameOddsSnapshot;
use App\Models\MLB\Game as MlbGame;
use App\Models\NBA\Game as NbaGame;
use App\Models\NFL\Game as NflGame;
use App\Models\WCBB\Game as WcbbGame;
use App\Models\WNBA\Game as WnbaGame;
use App\Services\OddsApi\MarketQuoteRecorder;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Throwable;

class BackfillMarketQuotesCommand extends Command
{
    /**
     * @var array<string, class-string<Model>>
     */
    private const GAME_MODELS = [
        'cbb' => CbbGame::class,
        'cfb' => CfbGame::class,
        'mlb' => MlbGame::class,
        'nba' => NbaGame::class,
        'nfl' => NflGame::class,
        'wcbb' => WcbbGame::class,
        'wnba' => WnbaGame::class,
    ];

    protected $signature = 'sports:backfill-market-quotes
        {--sport=* : Sport keys to backfill (defaults to all)}
        {--season= : Limit snapshots to games in this season}
        {--date= : Limit snapshots to games on this date (YYYY-MM-DD)}
        {--chunk=250 : Number of snapshots to process per database chunk}
        {--limit=0 : Maximum snapshots to process}
        {--dry-run : Normalize and report without writing market quotes}';

    protected $description = 'Normalize archived game odds snapshots into immutable per-book market quotes';

    public function handle(MarketQuoteRecorder $recorder): int
    {
        $sports = collect((array) $this->option('sport'))
            ->map(fn (mixed $sport): string => strtolower(trim((string) $sport)))
            ->filter()
            ->unique()
            ->values();
        $season = $this->seasonOption();
        $date = $this->dateOption();
        $chunkSize = $this->boundedIntegerOption('chunk', minimum: 1, maximum: 5000);
        $limit = $this->boundedIntegerOption('limit', minimum: 0);

        if ($season === false || $date === false || $chunkSize === false || $limit === false) {
            return self::FAILURE;
        }

        $filterSports = $sports->isEmpty()
            ? collect(array_keys(self::GAME_MODELS))
            : $sports;

        if (($season !== null || $date !== null) && $filterSports->contains(
            fn (string $sport): bool => ! array_key_exists($sport, self::GAME_MODELS)
        )) {
            $unsupported = $filterSports
                ->reject(fn (string $sport): bool => array_key_exists($sport, self::GAME_MODELS))
                ->implode(', ');
            $this->error("Season/date filters are not supported for: {$unsupported}.");

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $query = $this->snapshotQuery($sports->all(), $filterSports->all(), $season, $date);
        $selected = (clone $query)->count();

        if ($limit > 0) {
            $selected = min($selected, $limit);
        }

        $result = $this->emptyReport($selected);
        $counterKeys = [
            'bookmakers_seen',
            'markets_seen',
            'outcomes_seen',
            'quotes_normalized',
            'quotes_inserted',
            'quotes_existing',
            'malformed_entries',
            'skipped_markets',
            'skipped_outcomes',
            'skipped_pairs',
            'post_start_quotes',
            'unknown_start_quotes',
        ];

        $query->chunkById($chunkSize, function ($snapshots) use (
            &$result,
            $counterKeys,
            $dryRun,
            $limit,
            $recorder,
        ): bool {
            foreach ($snapshots as $snapshot) {
                if ($limit > 0 && $result['snapshots_processed'] >= $limit) {
                    return false;
                }

                $snapshotResult = $recorder->recordWithReport(
                    $snapshot,
                    (array) $snapshot->odds_data,
                    $dryRun,
                );
                $result['snapshots_processed']++;

                foreach ($counterKeys as $key) {
                    $result[$key] += $snapshotResult[$key];
                }

                foreach ($snapshotResult['issues'] as $issue) {
                    if (count($result['issues']) >= 25) {
                        break;
                    }

                    $result['issues'][] = [
                        'snapshot_id' => (int) $snapshot->getKey(),
                        'reason' => $issue['reason'],
                        'context' => $issue['context'],
                    ];
                }
            }

            return true;
        });

        $this->displayReport($result, $dryRun);

        return self::SUCCESS;
    }

    /**
     * @param  array<int, string>  $sports
     * @param  array<int, string>  $filterSports
     * @return Builder<GameOddsSnapshot>
     */
    private function snapshotQuery(
        array $sports,
        array $filterSports,
        ?int $season,
        ?CarbonInterface $date,
    ): Builder {
        $query = GameOddsSnapshot::query()->orderBy('id');

        if ($sports !== []) {
            $query->whereIn('sport', $sports);
        }

        if ($season === null && $date === null) {
            return $query;
        }

        return $query->where(function (Builder $snapshotQuery) use ($filterSports, $season, $date): void {
            foreach ($filterSports as $sport) {
                $modelClass = self::GAME_MODELS[$sport];
                $game = new $modelClass;
                $games = $modelClass::query()->select($game->getKeyName());

                if ($season !== null) {
                    $games->where('season', $season);
                }

                if ($date !== null) {
                    $games->whereDate('game_date', $date->toDateString());
                }

                $snapshotQuery->orWhere(function (Builder $sportQuery) use ($sport, $game, $games): void {
                    $sportQuery
                        ->where('sport', $sport)
                        ->where('game_table', $game->getTable())
                        ->whereIn('game_id', $games);
                });
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyReport(int $selected): array
    {
        return [
            'snapshots_selected' => $selected,
            'snapshots_processed' => 0,
            'bookmakers_seen' => 0,
            'markets_seen' => 0,
            'outcomes_seen' => 0,
            'quotes_normalized' => 0,
            'quotes_inserted' => 0,
            'quotes_existing' => 0,
            'malformed_entries' => 0,
            'skipped_markets' => 0,
            'skipped_outcomes' => 0,
            'skipped_pairs' => 0,
            'post_start_quotes' => 0,
            'unknown_start_quotes' => 0,
            'issues' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function displayReport(array $result, bool $dryRun): void
    {
        $wouldInsert = $result['quotes_normalized'] - $result['quotes_existing'];

        $this->info($dryRun
            ? 'Market quote backfill dry run completed.'
            : 'Market quote backfill completed.');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Snapshots selected', $result['snapshots_selected']],
                ['Snapshots processed', $result['snapshots_processed']],
                ['Bookmakers seen', $result['bookmakers_seen']],
                ['Markets seen', $result['markets_seen']],
                ['Outcomes seen', $result['outcomes_seen']],
                ['Quotes normalized', $result['quotes_normalized']],
                [$dryRun ? 'Quotes that would be inserted' : 'Quotes inserted', $dryRun
                    ? $wouldInsert
                    : $result['quotes_inserted']],
                ['Quotes already present', $result['quotes_existing']],
                ['Post-start quotes marked non-pregame', $result['post_start_quotes']],
                ['Quotes with unknown start status', $result['unknown_start_quotes']],
                ['Malformed entries', $result['malformed_entries']],
                ['Skipped markets', $result['skipped_markets']],
                ['Skipped outcomes', $result['skipped_outcomes']],
                ['Skipped incomplete pairs', $result['skipped_pairs']],
            ],
        );

        if ($result['issues'] !== []) {
            $this->warn('Representative malformed or skipped payload entries:');
            $this->table(
                ['Snapshot', 'Reason', 'Context'],
                collect($result['issues'])
                    ->map(fn (array $issue): array => [
                        $issue['snapshot_id'],
                        $issue['reason'],
                        $issue['context'],
                    ])
                    ->all(),
            );
        }
    }

    private function seasonOption(): int|false|null
    {
        $season = $this->option('season');

        if ($season === null || $season === '') {
            return null;
        }

        if (! ctype_digit((string) $season) || (int) $season < 1876) {
            $this->error('The --season option must be a valid season year.');

            return false;
        }

        return (int) $season;
    }

    private function dateOption(): CarbonImmutable|false|null
    {
        $date = $this->option('date');

        if ($date === null || $date === '') {
            return null;
        }

        try {
            $parsed = CarbonImmutable::createFromFormat('!Y-m-d', (string) $date);
        } catch (Throwable) {
            $parsed = false;
        }

        if ($parsed === false || $parsed->format('Y-m-d') !== (string) $date) {
            $this->error('The --date option must use YYYY-MM-DD format.');

            return false;
        }

        return $parsed;
    }

    private function boundedIntegerOption(string $option, int $minimum, ?int $maximum = null): int|false
    {
        $value = $this->option($option);
        $isValid = ctype_digit((string) $value)
            && (int) $value >= $minimum
            && ($maximum === null || (int) $value <= $maximum);

        if (! $isValid) {
            $range = $maximum === null
                ? "{$minimum} or greater"
                : "between {$minimum} and {$maximum}";
            $this->error("The --{$option} option must be {$range}.");

            return false;
        }

        return (int) $value;
    }
}
