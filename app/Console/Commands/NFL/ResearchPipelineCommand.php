<?php

namespace App\Console\Commands\NFL;

use App\Models\NFL\Game;
use App\Models\NFL\ResearchRevision;
use App\Services\NFL\Research\OfficialSourceIngestor;
use App\Services\NFL\Research\ResearchPipeline;
use App\Services\Sports\SportsDateWindowService;
use Illuminate\Console\Command;
use Throwable;

class ResearchPipelineCommand extends Command
{
    protected $signature = 'nfl:research-pipeline {--date=} {--days-forward=1} {--limit=16} {--ingest-only} {--no-ingest} {--no-web} {--grade} {--briefs}';

    protected $description = 'Ingest official NFL updates, research both sides, preserve revised forecasts and evaluate outcomes';

    public function handle(OfficialSourceIngestor $ingestor, ResearchPipeline $pipeline, SportsDateWindowService $dates): int
    {
        if (! config('nfl_research.enabled')) {
            $this->error('NFL research pipeline disabled');

            return self::FAILURE;
        }
        if ($this->option('grade')) {
            $graded = 0;
            ResearchRevision::query()->chunkById(100, function ($rows) use ($pipeline, &$graded) {
                foreach ($rows as $row) {
                    $graded += (int) $pipeline->grade($row);
                }
            });
            $this->info('Graded '.$graded.' immutable research revisions');

            return self::SUCCESS;
        }
        $start = $dates->parseLocalDate($this->option('date'));
        $window = $dates->forRange($start, $start->addDays(max(0, (int) $this->option('days-forward'))));
        $games = Game::with(['homeTeam', 'awayTeam', 'prediction'])->where(fn ($q) => $dates->applyGameDateWindow($q, $window))->orderBy('game_date')->orderBy('game_time')->get();
        if ($this->option('briefs')) {
            foreach ($games as $game) {
                $revision = ResearchRevision::where('game_id', $game->id)->latest('id')->first();
                if ($revision) {
                    $this->line(json_encode(['revision_id' => $revision->id, 'baseline' => $revision->baseline, 'revised' => $revision->revised, 'brief' => $revision->brief, 'market' => $revision->market], JSON_UNESCAPED_SLASHES));
                }
            }

            return self::SUCCESS;
        }
        $games = $games->where('status', 'STATUS_SCHEDULED');
        $teams = $games->flatMap(fn ($g) => [$g->homeTeam?->abbreviation, $g->awayTeam?->abbreviation])->map(fn ($t) => $t === 'WSH' ? 'WAS' : $t)->filter()->unique()->all();
        $failed = false;
        if (! $this->option('no-ingest')) {
            $result = $ingestor->sync($teams);
            $this->line(json_encode(['sources' => $result]));
            $failed = collect($result)->contains(fn ($r) => isset($r['error']));
        }
        if ($this->option('ingest-only')) {
            return $failed ? self::FAILURE : self::SUCCESS;
        }
        // Process least recently reviewed games first to avoid starving the late window.
        $games = $games->sortBy(fn ($g) => ResearchRevision::where('game_id', $g->id)->max('created_at') ?? '0000');
        foreach ($games->take(max(1, (int) $this->option('limit'))) as $game) {
            try {
                $revision = $pipeline->review($game, ! $this->option('no-web'));
                if ($revision) {
                    $this->line(json_encode(['game_id' => $game->id, 'revision_id' => $revision->id, 'new_revision' => $revision->wasRecentlyCreated, 'eligibility' => $revision->brief['eligibility'], 'change' => $revision->brief['change']]));
                }
            } catch (Throwable $e) {
                $failed = true;
                $this->error('Game '.$game->id.' research failed: '.class_basename($e));
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
