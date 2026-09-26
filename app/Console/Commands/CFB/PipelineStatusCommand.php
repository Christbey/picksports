<?php

namespace App\Console\Commands\CFB;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PipelineStatusCommand extends Command
{
    protected $signature = 'cfb:pipeline-status {--game=} {--limit=50}';

    protected $description = 'Show CFB stage evidence, pending work, attempts and freshness without changing data';

    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        if ($limit < 1 || $limit > 500) {
            return self::FAILURE;
        }
        $query = DB::table('cfb_pipeline_steps')->when($this->option('game'), fn ($q, $v) => $q->where('scope', 'game:'.$v));
        // Select the latest attempt for each scope/stage; superseded failures are not active incidents.
        $ids = (clone $query)->selectRaw('MAX(id) AS id')->groupBy('scope', 'stage')->pluck('id');
        $latest = DB::table('cfb_pipeline_steps')->whereIn('id', $ids)->orderByDesc('updated_at')->get();
        $this->line(json_encode(['counts' => $latest->countBy('state')->all(),
            'oldest_pending_at' => $latest->where('state', '!=', 'complete')->min('updated_at'),
            'stages' => $latest->take($limit)->map(fn ($row) => ['scope' => $row->scope, 'stage' => $row->stage, 'state' => $row->state,
                'attempts' => $row->attempts, 'updated_at' => $row->updated_at, 'evidence' => json_decode($row->result ?? 'null', true)])->values()->all()], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
