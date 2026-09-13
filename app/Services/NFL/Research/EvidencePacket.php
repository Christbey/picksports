<?php

namespace App\Services\NFL\Research;

use App\Models\NFL\Game;
use App\Models\NFL\Player;
use App\Models\NFL\ResearchDocument;
use App\Models\NFL\ResearchSource;
use Illuminate\Support\Facades\Schema;

class EvidencePacket
{
    public function forGame(Game $game): array
    {
        if (! Schema::hasTable('nfl_research_documents')) {
            return ['documents' => [], 'availability' => [], 'holds' => ['source_ingestion_not_installed']];
        }
        $teams = array_map(fn ($t) => $t === 'WSH' ? 'WAS' : $t, [$game->homeTeam?->abbreviation, $game->awayTeam?->abbreviation]);
        $documents = ResearchDocument::whereIn('team', $teams)->where('observed_at', '<=', now())->where('observed_at', '>=', now()->subDays(180))
            ->orderByDesc('observed_at')->orderByDesc('id')->get()->unique('url_hash')->values();
        $holds = [];
        foreach ($teams as $team) {
            foreach (['rss', 'newsroom', 'roster', 'injury'] as $kind) {
                if (! ResearchSource::where('team', $team)->where('kind', $kind)->whereNull('error')->where('succeeded_at', '>=', now()->subMinutes(45))->exists()) {
                    $holds[] = 'stale_or_missing_'.$kind.'_'.$team;
                }
            }
        }
        $availability = [];
        $players = Player::whereIn('team_id', [$game->home_team_id, $game->away_team_id])->get();
        foreach ($documents as $document) {
            if (is_array($document->structured)) {
                $source = ResearchSource::find($document->source_id);
                if (! $source?->succeeded_at || $source->succeeded_at->lt(now()->subMinutes(45)) || $source->error) {
                    $holds[] = 'stale_roster_'.$document->team;

                    continue;
                }
                $teamId = $document->team === $teams[0] ? $game->home_team_id : $game->away_team_id;
                foreach ($document->structured as $row) {
                    if (! preg_match('/reserve|physically unable|exempt|suspend/i', $row['roster_status']) || preg_match('/practice squad/i', $row['roster_status'])) {
                        continue;
                    }
                    $normalize = fn ($name) => preg_replace('/[^\p{L}\p{N}]/u', '', mb_strtolower($name));
                    $matches = $players->where('team_id', $teamId)->filter(fn ($p) => $normalize($p->full_name) === $normalize($row['player_name']));
                    if ($matches->count() !== 1) {
                        $holds[] = 'unlinked_reserve_player:'.$row['player_name'];

                        continue;
                    }
                    $player = $matches->first();
                    $availability[$player->id] = ['player_id' => $player->id, 'player_name' => $player->full_name, 'team_id' => $teamId, 'status' => 'Out', 'document_id' => $document->id, 'source_url' => $document->url, 'published_at' => $source->succeeded_at->toIso8601String(), 'observed_at' => $source->succeeded_at->toIso8601String(), 'injury_onset_known' => false, 'evidence' => $row['roster_status'].' on current official roster; injury onset unknown.', 'certainty' => 'confirmed'];
                }

                continue;
            }
            if (! $document->published_at || $document->published_at->gt(now()) || $document->published_at->lt(now()->subDays(14))) {
                continue;
            }
            $teamId = $document->team === $teams[0] ? $game->home_team_id : $game->away_team_id;
            foreach ($players->where('team_id', $teamId) as $player) {
                // Strict title grammar only; narrative mentions and table ordering cannot set availability.
                $name = preg_quote($player->full_name, '/');
                $status = null;
                if (preg_match('/\bplace(?:s|d)?\s+(?:[A-Z\/]+\s+)?'.$name.'\s+on\s+(?:injured reserve|IR|(?:reserve\/)?PUP)\b/iu', $document->title)) {
                    $status = 'Out';
                }
                if (preg_match('/\b'.$name.'\s+(?:is\s+)?(?:ruled\s+)?out\s+(?:for|vs\.?|against)\b/iu', $document->title) && $document->published_at->gte(now()->subDays(3))) {
                    $status = 'Out';
                }
                if (preg_match('/\bactivate(?:s|d)?\s+(?:[A-Z\/]+\s+)?'.$name.'\s+from\s+(?:injured reserve|IR|(?:reserve\/)?PUP)\b/iu', $document->title)) {
                    // Activation clears an old reserve transaction, not a game injury designation.
                    $status = 'ReserveActivated';
                }
                if (! $status) {
                    continue;
                }
                $fact = ['player_id' => $player->id, 'player_name' => $player->full_name, 'team_id' => $teamId, 'status' => $status, 'document_id' => $document->id, 'source_url' => $document->url, 'published_at' => $document->published_at->toIso8601String(), 'observed_at' => $document->observed_at->toIso8601String(), 'evidence' => $document->title, 'certainty' => 'confirmed'];
                $existing = $availability[$player->id] ?? null;
                if (! $existing || strcmp($fact['published_at'], $existing['published_at']) > 0) {
                    $availability[$player->id] = $fact;
                }
            }
        }

        foreach ($availability as $id => $fact) {
            if ($fact['status'] === 'ReserveActivated') {
                $holds[] = 'activation_requires_game_status:'.$fact['player_name'];
                unset($availability[$id]);
            }
        }

        return ['documents' => $documents->map(fn ($d) => ['id' => $d->id, 'team' => $d->team, 'url' => $d->url, 'title' => $d->title, 'published_at' => $d->published_at?->toIso8601String(), 'observed_at' => $d->observed_at->toIso8601String(), 'content_hash' => $d->content_hash, 'structured' => $d->structured, 'text' => mb_substr($d->body, 0, 12000)])->all(), 'availability' => array_values($availability), 'holds' => $holds];
    }

    public function researchDocuments(array $packet): array
    {
        return collect($packet['documents'])->groupBy('team')->flatMap(function ($docs) {
            return $docs->sortByDesc(fn ($d) => (preg_match('/injur|roster|reserve|preview|coach|practice/i', $d['title']) ? 1 : 0))->take(6);
        })->values()->all();
    }

    public function reconcile(array $payload, array $packet): array
    {
        foreach ($packet['availability'] as $fact) {
            foreach ($payload['facts'] ?? [] as $index => $claim) {
                if ($fact['status'] === 'Out' && stripos($claim['claim'], $fact['player_name']) !== false && preg_match('/questionable|available|cleared|will play/i', $claim['claim'])) {
                    $payload['facts'][$index]['certainty'] = 'uncertain';
                    $payload['facts'][$index]['superseded_by_document_id'] = $fact['document_id'];
                    $payload['summary'] = ($payload['summary'] ?? '').' Verified correction: '.$fact['player_name'].' is '.$fact['status'].'.';
                    $payload['status'] = 'partial';
                    $payload['risk_flags'][] = 'source_conflict: '.$fact['player_name'].' has an official unavailable-status transaction; see '.$fact['source_url'];
                }
            }
            $payload['facts'][] = ['category' => 'injury', 'team_side' => 'game', 'claim' => $fact['player_name'].': '.$fact['status'].'. '.$fact['evidence'], 'certainty' => 'confirmed', 'source_urls' => [$fact['source_url']], 'player_id' => $fact['player_id'], 'effective_at' => $fact['published_at'], 'observed_at' => $fact['observed_at'], 'document_id' => $fact['document_id']];
        }
        $payload['risk_flags'] = array_values(array_unique(array_merge($payload['risk_flags'] ?? [], $packet['holds'])));
        if ($packet['holds'] !== []) {
            $payload['status'] = 'partial';
        }

        return $payload;
    }
}
