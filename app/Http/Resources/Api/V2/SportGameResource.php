<?php

namespace App\Http\Resources\Api\V2;

use App\Services\Api\V2\SportContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\MissingValue;

class SportGameResource extends JsonResource
{
    public function __construct(
        mixed $resource,
        private readonly SportContext $context,
    ) {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sport' => $this->context->slug,
            'espn_id' => $this->espn_id ?? $this->espn_event_id ?? null,
            'espn_event_id' => $this->espn_event_id ?? $this->espn_id ?? null,
            'espn_uid' => $this->espn_uid ?? null,
            'season' => $this->season ?? null,
            'season_type' => $this->season_type ?? null,
            'week' => $this->week ?? null,
            'postseason_round' => $this->postseason_round ?? null,
            'name' => $this->name ?? null,
            'short_name' => $this->short_name ?? null,
            'game_date' => $this->serializeDateValue($this->game_date ?? null),
            'game_time' => $this->serializeTimeValue($this->game_time ?? null),
            'venue' => $this->venue_name ?? $this->venue ?? null,
            'venue_name' => $this->venue_name ?? $this->venue ?? null,
            'venue_city' => $this->venue_city ?? null,
            'venue_state' => $this->venue_state ?? null,
            'attendance' => $this->attendance ?? null,
            'status' => $this->status ?? null,
            'period' => $this->period ?? null,
            'clock' => $this->game_clock ?? $this->clock ?? null,
            'game_clock' => $this->game_clock ?? $this->clock ?? null,
            'home_team_id' => $this->home_team_id ?? null,
            'away_team_id' => $this->away_team_id ?? null,
            'home_score' => $this->home_score ?? null,
            'away_score' => $this->away_score ?? null,
            'home_linescores' => $this->home_linescores ?? null,
            'away_linescores' => $this->away_linescores ?? null,
            'broadcast_networks' => $this->broadcast_networks ?? null,
            'inning' => $this->inning ?? null,
            'inning_half' => $this->inning_half ?? null,
            'balls' => $this->balls ?? null,
            'strikes' => $this->strikes ?? null,
            'outs' => $this->outs ?? null,
            'probable_home_pitcher_espn_id' => $this->probable_home_pitcher_espn_id ?? null,
            'probable_away_pitcher_espn_id' => $this->probable_away_pitcher_espn_id ?? null,
            'is_ncaa_tournament' => (bool) ($this->is_ncaa_tournament ?? false),
            'tournament_id' => $this->tournament_id ?? null,
            'tournament_note' => $this->tournament_note ?? null,
            'tournament_round' => $this->tournament_round ?? null,
            'tournament_region' => $this->tournament_region ?? null,
            'home_seed' => $this->home_seed ?? null,
            'away_seed' => $this->away_seed ?? null,
            'play_in_target_seed' => $this->play_in_target_seed ?? null,
            'matchup_context' => $this->resource->getAttribute('matchup_context'),
            'home_team' => $this->teamPayload($this->whenLoaded('homeTeam')),
            'away_team' => $this->teamPayload($this->whenLoaded('awayTeam')),
            'home_starting_pitcher' => $this->playerPayload($this->whenLoaded('probableHomePitcher')),
            'away_starting_pitcher' => $this->playerPayload($this->whenLoaded('probableAwayPitcher')),
            'has_prediction' => $this->relationLoaded('prediction') && $this->prediction !== null,
            'completed_at' => $this->serializeDateValue($this->completed_at ?? null),
            'updated_at' => $this->serializeDateValue($this->updated_at ?? null),
        ];
    }

    private function serializeDateValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_object($value) && method_exists($value, 'toIso8601String')) {
            return $value->toIso8601String();
        }

        return (string) $value;
    }

    private function serializeTimeValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_object($value) && method_exists($value, 'format')) {
            return $value->format('H:i:s');
        }

        $time = (string) $value;

        if (preg_match('/\b(\d{2}:\d{2}(?::\d{2})?)\b/', $time, $matches) === 1) {
            return strlen($matches[1]) === 5 ? "{$matches[1]}:00" : $matches[1];
        }

        return $time;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function teamPayload(mixed $team): ?array
    {
        if (! $team || $team instanceof MissingValue) {
            return null;
        }

        return [
            'id' => $team->id,
            'espn_id' => $team->espn_id ?? null,
            'abbreviation' => $team->abbreviation ?? null,
            'location' => $team->location ?? $team->school ?? null,
            'name' => $team->name ?? $team->mascot ?? null,
            'nickname' => $team->nickname ?? null,
            'display_name' => $team->display_name ?? $this->displayName($team),
            'short_display_name' => $team->short_display_name ?? $team->abbreviation ?? null,
            'conference' => $team->conference ?? null,
            'league' => $team->league ?? null,
            'division' => $team->division ?? null,
            'color' => $team->color ?? null,
            'alternate_color' => $team->alternate_color ?? null,
            'logo' => $team->logo_url ?? null,
            'logo_url' => $team->logo_url ?? null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function playerPayload(mixed $player): ?array
    {
        if (! $player || $player instanceof MissingValue) {
            return null;
        }

        return [
            'id' => $player->id,
            'espn_id' => $player->espn_id ?? null,
            'full_name' => $player->full_name ?? $player->display_name ?? trim((string) ($player->first_name ?? '').' '.(string) ($player->last_name ?? '')),
            'display_name' => $player->display_name ?? $player->full_name ?? null,
            'headshot_url' => $player->headshot_url ?? null,
            'position' => $player->position ?? null,
            'elo_rating' => $player->elo_rating ?? null,
        ];
    }

    private function displayName(mixed $team): ?string
    {
        $parts = array_filter([
            $team->location ?? $team->school ?? null,
            $team->name ?? $team->nickname ?? $team->mascot ?? null,
        ]);

        return $parts === [] ? null : implode(' ', $parts);
    }
}
