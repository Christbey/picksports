<?php

namespace App\Models\CFB;

use App\Services\CFB\Data\CfbSourceAssessmentRecorder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GameDataCheck extends Model
{
    protected $table = 'cfb_game_data_checks';

    protected $guarded = ['id'];

    public function save(array $options = []): bool
    {
        if (! $this->exists) {
            return parent::save($options);
        }

        return app(CfbSourceAssessmentRecorder::class)
            ->saveProjection($this, fn (): bool => parent::save($options));
    }

    public function isCurrentAccepted(): bool
    {
        return app(CfbSourceAssessmentRecorder::class)->isAccepted($this);
    }

    protected function casts(): array
    {
        return ['discrepancies' => 'array', 'evidence' => 'array', 'accepted_at' => 'immutable_datetime'];
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class, 'game_id');
    }
}
