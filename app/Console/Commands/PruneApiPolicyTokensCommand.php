<?php

namespace App\Console\Commands;

use App\Services\Auth\Policy\ApiTokenPolicyAuthenticator;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Laravel\Sanctum\Sanctum;

/** Bounded epoch cleanup; vendor naive-date pruning does not select these rows. */
final class PruneApiPolicyTokensCommand extends Command
{
    protected $signature = 'auth:prune-policy-tokens {--hours=24} {--limit=1000} {--after-id=0} {--apply}';

    protected $description = 'Preview or remove one bounded batch of expired policy API tokens';

    public function handle(): int
    {
        $hours = $this->option('hours');
        $limit = $this->option('limit');
        $after = $this->option('after-id');
        foreach ([$hours, $limit, $after] as $value) {
            if (! is_string($value) || ! preg_match('/\A(?:0|[1-9][0-9]{0,15})\z/', $value)) {
                $this->error('Invalid pruning bounds.');

                return self::FAILURE;
            }
        }
        if ((int) $hours > 8760 || (int) $limit < 1 || (int) $limit > 1000) {
            $this->error('Use hours 0..8760 and limit 1..1000.');

            return self::FAILURE;
        }
        $model = new (Sanctum::$personalAccessTokenModel);
        $cutoff = CarbonImmutable::now('UTC')->getTimestamp() - (int) $hours * 3600;
        $query = fn () => $model->newQuery()->where('api_policy_version', ApiTokenPolicyAuthenticator::VERSION)
            ->whereIn('api_token_profile', array_keys(ApiTokenPolicyAuthenticator::PROFILES))
            ->whereNull('expires_at')->where('api_expires_epoch', '>', 0)->where('api_expires_epoch', '<=', $cutoff)
            ->where('personal_access_tokens.id', '>', (int) $after)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('device_sessions')
                ->whereColumn('device_sessions.access_token_id', 'personal_access_tokens.id'));
        $result = $model->getConnection()->transaction(function () use ($query, $limit): array {
            $rows = $query()->orderBy('personal_access_tokens.id')->limit((int) $limit + 1)->lockForUpdate()->get(['personal_access_tokens.id']);
            $ids = $rows->take((int) $limit)->modelKeys();
            $deleted = $this->option('apply') && $ids !== [] ? $query()->whereKey($ids)->delete() : 0;

            return ['eligible' => count($ids), 'deleted' => $deleted, 'more' => $rows->count() > (int) $limit, 'next_after_id' => $ids === [] ? null : max($ids)];
        });
        $this->line(json_encode(['mode' => $this->option('apply') ? 'apply' : 'dry_run', ...$result], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
