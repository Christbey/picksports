<?php

namespace App\Models\NFL;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResearchRevision extends Model
{
    protected $table = 'nfl_research_revisions';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['baseline' => 'array', 'revised' => 'array', 'evidence' => 'array', 'brief' => 'array', 'market' => 'array', 'evaluation' => 'array', 'created_at' => 'immutable_datetime', 'grade_attempted_at' => 'immutable_datetime', 'graded_at' => 'immutable_datetime'];
    }

    public static function baselineForGame(int $gameId): ?self
    {
        $id = static::where('game_id', $gameId)->min('id');

        return $id === null ? null : static::query()->find($id, ['id', 'baseline']);
    }

    public static function latestSummaryForGame(int $gameId): ?self
    {
        $id = static::where('game_id', $gameId)->max('id');

        return $id === null ? null : static::query()->find($id, ['id', 'report_id', 'input_hash', 'revised', 'brief']);
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class, 'game_id');
    }
}
