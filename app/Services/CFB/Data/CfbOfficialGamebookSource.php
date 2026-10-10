<?php

namespace App\Services\CFB\Data;

use App\Models\CFB\Game;
use App\Models\ProviderSourceFile;
use App\Services\BettingRecommendations\CfbPropEligibility;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class CfbOfficialGamebookSource
{
    public function archive(Game $game, array $spec): array
    {
        $this->assertIdentity($game, $spec);
        if (parse_url($spec['url'], PHP_URL_SCHEME) !== 'https') {
            throw new RuntimeException('A reviewed official source must use HTTPS.');
        }
        $body = Http::timeout(30)->connectTimeout(10)->get($spec['url'])->throw()->body();
        if (strlen($body) > 5 * 1024 * 1024 || ! hash_equals($spec['sha256'], hash('sha256', $body))) {
            throw new RuntimeException('Official gamebook changed or exceeded the size limit; a new review is required.');
        }
        $diskName = config('cfb.data.source_disk', 'local');
        $disk = Storage::disk($diskName);
        $path = 'cfb/sources/'.$game->id.'/official-gamebook/'.$spec['sha256'].($spec['content_type'] === 'application/pdf' ? '.pdf' : '.html');
        if (! $disk->exists($path) && ! $disk->put($path, $body)) {
            throw new RuntimeException('Official gamebook archive failed.');
        }
        ProviderSourceFile::firstOrCreate(['provider' => 'school_official', 'dataset' => 'cfb_gamebook', 'sha256' => $spec['sha256']],
            ['disk' => $diskName, 'object_key' => $path, 'uri' => $diskName.':'.$path, 'original_filename' => basename($path),
                'content_type' => $spec['content_type'], 'size_bytes' => strlen($body),
                'metadata' => ['game_id' => $game->id, 'source_url' => $spec['url'], 'reference' => $spec['reference'],
                    'review_method' => 'agent_verified_official_tables_with_pinned_content_hash']]);

        return ['hash' => $spec['sha256'], 'path' => $path, 'url' => $spec['url'], 'reference' => $spec['reference']];
    }

    public function assertIdentity(Game $game, array $spec): void
    {
        $game->loadMissing(['homeTeam', 'awayTeam']);
        $normalize = fn ($name) => preg_replace('/[^a-z]/', '', strtolower(Str::ascii($name)));
        if ($game->status !== 'STATUS_FINAL' || (int) $game->season !== $spec['season']
            || CfbPropEligibility::kickoff($game)->setTimezone('America/Chicago')->toDateString() !== $spec['date']) {
            throw new RuntimeException('Official gamebook event date, season or final status does not match.');
        }
        foreach (['home', 'away'] as $side) {
            if ($normalize($game->{$side.'Team'}->school) !== $normalize($spec[$side])
                || (int) $game->{$side.'_score'} !== $spec[$side.'_score']) {
                throw new RuntimeException('Official gamebook team or final score does not match.');
            }
        }
    }
}
