<?php

namespace App\Models\NFL;

use App\Services\ProviderData\CompressedJsonPayloadStorage;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResearchRevision extends Model
{
    protected $table = 'nfl_research_revisions';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['baseline' => 'array', 'revised' => 'array', 'evidence' => 'array', 'payload_archive' => 'array', 'brief' => 'array', 'market' => 'array', 'evaluation' => 'array', 'created_at' => 'immutable_datetime', 'grade_attempted_at' => 'immutable_datetime', 'graded_at' => 'immutable_datetime'];
    }

    public static function baselineForGame(int $gameId): ?self
    {
        $id = static::where('game_id', $gameId)->min('id');

        return $id === null ? null : static::query()->find($id, ['id', 'baseline']);
    }

    public static function latestSummaryForGame(int $gameId): ?self
    {
        $id = static::where('game_id', $gameId)->max('id');

        return $id === null ? null : static::query()->find($id, ['id', 'report_id', 'input_hash', 'revised', 'brief', 'payload_archive']);
    }

    protected function brief(): Attribute
    {
        return Attribute::get(fn (?string $value): ?array => $this->decodePayload('brief', $value));
    }

    protected function evidence(): Attribute
    {
        return Attribute::get(fn (?string $value): ?array => $this->decodePayload('evidence', $value));
    }

    private function decodePayload(string $field, ?string $value): ?array
    {
        if ($value === 'null' && $this->payload_archive !== null) {
            $payload = app(CompressedJsonPayloadStorage::class)->read($this->payload_archive);
            $value = $payload[$field];
        }

        return $value === null ? null : json_decode($value, true, 512, JSON_THROW_ON_ERROR);
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class, 'game_id');
    }
}
