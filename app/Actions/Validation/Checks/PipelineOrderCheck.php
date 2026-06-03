<?php

namespace App\Actions\Validation\Checks;

use App\Actions\Validation\Contracts\ValidationCheck;
use App\Models\CommandHeartbeat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class PipelineOrderCheck implements ValidationCheck
{
    /**
     * @param  array<string, mixed>  $profile
     * @return array<string, mixed>|null
     */
    public function run(string $sport, array $profile): ?array
    {
        if (! Schema::hasTable('command_heartbeats')) {
            return null;
        }

        $rules = Collection::make($profile['pipeline_order'] ?? [])
            ->filter(fn ($rule) => is_array($rule) && isset($rule['upstream'], $rule['downstream']))
            ->values();

        if ($rules->isEmpty()) {
            return null;
        }

        $violations = [];
        $missingHeartbeats = [];

        foreach ($rules as $rule) {
            $upstream = (array) $rule['upstream'];
            $downstream = (array) $rule['downstream'];
            $label = (string) ($rule['label'] ?? 'pipeline dependency');
            $recommendedAction = (string) ($rule['recommended_action'] ?? ($downstream[0] ?? 'review manually'));
            $upstreamHeartbeat = $this->latestSuccess($sport, $upstream);
            $downstreamHeartbeat = $this->latestSuccess($sport, $downstream);

            if (! $upstreamHeartbeat || ! $downstreamHeartbeat) {
                $missingHeartbeats[] = [
                    'label' => $label,
                    'upstream' => $upstream,
                    'downstream' => $downstream,
                    'upstream_found' => $upstreamHeartbeat !== null,
                    'downstream_found' => $downstreamHeartbeat !== null,
                    'recommended_action' => $recommendedAction,
                ];

                continue;
            }

            if ($upstreamHeartbeat->ran_at->gt($downstreamHeartbeat->ran_at)) {
                $violations[] = [
                    'label' => $label,
                    'upstream_command' => $upstreamHeartbeat->command,
                    'upstream_ran_at' => $upstreamHeartbeat->ran_at->toDateTimeString(),
                    'downstream_command' => $downstreamHeartbeat->command,
                    'downstream_ran_at' => $downstreamHeartbeat->ran_at->toDateTimeString(),
                    'recommended_action' => $recommendedAction,
                ];
            }
        }

        $status = 'passing';
        $message = 'Pipeline order looks healthy for configured dependencies.';

        if ($violations !== []) {
            $status = 'failing';
            $message = count($violations).' pipeline dependency violation(s) need downstream reruns.';
        } elseif ($missingHeartbeats !== []) {
            $status = 'warning';
            $message = count($missingHeartbeats).' pipeline dependency check(s) are missing heartbeat history.';
        }

        $recommendedAction = (string) data_get($violations, '0.recommended_action', data_get($missingHeartbeats, '0.recommended_action'));

        return [
            'check_type' => 'validation_pipeline_order',
            'status' => $status,
            'severity' => $status,
            'message' => $message,
            'recommended_action' => $recommendedAction !== '' ? $recommendedAction : null,
            'metadata' => [
                'rules_checked' => $rules->count(),
                'violations' => array_slice($violations, 0, 5),
                'missing_heartbeats' => array_slice($missingHeartbeats, 0, 5),
            ],
        ];
    }

    /**
     * @param  array<int, string>  $patterns
     */
    private function latestSuccess(string $sport, array $patterns): ?CommandHeartbeat
    {
        return CommandHeartbeat::query()
            ->where('sport', $sport)
            ->where('status', 'success')
            ->where(function (Builder $query) use ($patterns) {
                foreach ($patterns as $index => $pattern) {
                    if ($index === 0) {
                        $query->where('command', 'like', $pattern);
                    } else {
                        $query->orWhere('command', 'like', $pattern);
                    }
                }
            })
            ->latest('ran_at')
            ->first();
    }
}
