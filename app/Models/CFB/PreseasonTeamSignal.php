<?php

namespace App\Models\CFB;

use Database\Factories\CfbPreseasonTeamSignalFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PreseasonTeamSignal extends Model
{
    /** @use HasFactory<CfbPreseasonTeamSignalFactory> */
    use HasFactory;

    public const QB_RETURNING_STARTER = 'returning_starter';

    public const QB_EXPERIENCED_TRANSFER = 'experienced_transfer';

    public const QB_NEW_TRANSFER = 'new_transfer';

    public const QB_FIRST_TIME_STARTER = 'first_time_starter';

    public const QB_INJURY_RETURN = 'injury_return';

    public const QB_UNKNOWN = 'unknown';

    protected $table = 'cfb_preseason_team_signals';

    protected $fillable = [
        'team_id',
        'season',
        'returning_percent_ppa',
        'returning_percent_passing_ppa',
        'returning_percent_rushing_ppa',
        'returning_percent_receiving_ppa',
        'returning_usage',
        'returning_passing_usage',
        'returning_rushing_usage',
        'returning_receiving_usage',
        'returning_total_ppa',
        'returning_total_passing_ppa',
        'returning_total_rushing_ppa',
        'returning_total_receiving_ppa',
        'returning_production_payload',
        'incoming_transfer_count',
        'outgoing_transfer_count',
        'incoming_transfer_value',
        'outgoing_transfer_value',
        'transfer_net_value',
        'transfer_qb_net_value',
        'transfer_ol_net_value',
        'transfer_dl_net_value',
        'transfer_wr_net_value',
        'transfer_cb_net_value',
        'transfer_position_summary',
        'transfer_portal_payload',
        'talent_composite',
        'talent_rank',
        'recruiting_rank',
        'recruiting_points',
        'recruiting_avg_rating',
        'talent_payload',
        'recruiting_payload',
        'qb_continuity_classification',
        'qb_continuity_confidence',
        'projected_starting_qb_name',
        'projected_starting_qb_source',
        'qb_continuity_payload',
        'new_head_coach',
        'new_offensive_coordinator',
        'new_defensive_coordinator',
        'coordinator_continuity_score',
        'head_coach_name',
        'offensive_coordinator_name',
        'defensive_coordinator_name',
        'coaching_continuity_payload',
        'data_quality_status',
        'synced_at',
    ];

    protected static function newFactory(): CfbPreseasonTeamSignalFactory
    {
        return CfbPreseasonTeamSignalFactory::new();
    }

    protected function casts(): array
    {
        return [
            'season' => 'integer',
            'returning_percent_ppa' => 'decimal:3',
            'returning_percent_passing_ppa' => 'decimal:3',
            'returning_percent_rushing_ppa' => 'decimal:3',
            'returning_percent_receiving_ppa' => 'decimal:3',
            'returning_usage' => 'decimal:3',
            'returning_passing_usage' => 'decimal:3',
            'returning_rushing_usage' => 'decimal:3',
            'returning_receiving_usage' => 'decimal:3',
            'returning_total_ppa' => 'decimal:3',
            'returning_total_passing_ppa' => 'decimal:3',
            'returning_total_rushing_ppa' => 'decimal:3',
            'returning_total_receiving_ppa' => 'decimal:3',
            'returning_production_payload' => 'array',
            'incoming_transfer_count' => 'integer',
            'outgoing_transfer_count' => 'integer',
            'incoming_transfer_value' => 'decimal:3',
            'outgoing_transfer_value' => 'decimal:3',
            'transfer_net_value' => 'decimal:3',
            'transfer_qb_net_value' => 'decimal:3',
            'transfer_ol_net_value' => 'decimal:3',
            'transfer_dl_net_value' => 'decimal:3',
            'transfer_wr_net_value' => 'decimal:3',
            'transfer_cb_net_value' => 'decimal:3',
            'transfer_position_summary' => 'array',
            'transfer_portal_payload' => 'array',
            'talent_composite' => 'decimal:3',
            'talent_rank' => 'integer',
            'recruiting_rank' => 'integer',
            'recruiting_points' => 'decimal:3',
            'recruiting_avg_rating' => 'decimal:4',
            'talent_payload' => 'array',
            'recruiting_payload' => 'array',
            'qb_continuity_confidence' => 'decimal:3',
            'qb_continuity_payload' => 'array',
            'new_head_coach' => 'boolean',
            'new_offensive_coordinator' => 'boolean',
            'new_defensive_coordinator' => 'boolean',
            'coordinator_continuity_score' => 'decimal:3',
            'coaching_continuity_payload' => 'array',
            'synced_at' => 'datetime',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'team_id');
    }

    public function scopeSeason(Builder $query, int $season): Builder
    {
        return $query->where('season', $season);
    }

    public function scopeWithKnownQuarterback(Builder $query): Builder
    {
        return $query->where('qb_continuity_classification', '!=', self::QB_UNKNOWN);
    }

    public static function quarterbackClassifications(): array
    {
        return [
            self::QB_RETURNING_STARTER,
            self::QB_EXPERIENCED_TRANSFER,
            self::QB_NEW_TRANSFER,
            self::QB_FIRST_TIME_STARTER,
            self::QB_INJURY_RETURN,
            self::QB_UNKNOWN,
        ];
    }
}
