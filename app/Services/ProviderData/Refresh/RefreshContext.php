<?php

namespace App\Services\ProviderData\Refresh;

use App\Models\ProviderRefreshAttempt;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Throwable;

final class RefreshContext
{
    private bool $finished = false;

    private array $history = [];

    public readonly RefreshBudget $budget;

    public function __construct(private readonly ProviderRefreshCoordinator $coordinator, public readonly Model $team,
        public readonly ?ProviderRefreshAttempt $attempt, public readonly ?string $owner, public readonly array $scope = [])
    {
        $this->budget = new RefreshBudget;
    }

    public function commit(bool $reliable, array $rows, Closure $write): int
    {
        if ($this->attempt !== null) {
            $this->budget->rows(count($rows));
            $this->budget->remaining();
        }
        $result = $this->coordinator->commit($this, $reliable, $rows, $write);
        $this->finished = true;

        return $result;
    }

    public function recordHistory(Model $snapshot): void
    {
        if ($this->attempt === null) {
            return;
        }
        $expected = $this->scope['component'] === 'injuries' ? 'nfl_player_injury_snapshots' : 'nfl_depth_chart_snapshots';
        if (($this->scope['sport'] ?? null) !== 'nfl' || $snapshot->getTable() !== $expected || ! $snapshot->exists
            || $snapshot->getConnection()->getName() !== $this->team->getConnection()->getName()
            || (int) $snapshot->team_id !== (int) $this->team->id
            || ($this->scope['component'] === 'depth_chart' && (int) $snapshot->season !== $this->scope['season_key'])) {
            throw new \RuntimeException('Refresh historical snapshot does not match the actual applied scope.');
        }
        $this->history = [$this->scope['component'] === 'injuries' ? 'nfl_injury_snapshot_id' : 'nfl_depth_snapshot_id' => $snapshot->id];
    }

    public function historyFields(): array
    {
        return $this->history;
    }

    public function fail(Throwable $error): void
    {
        if (! $this->finished) {
            $this->finished = true;
            try {
                $this->coordinator->finish($this, 'failed', 'acquisition_or_projection_failed');
            } catch (Throwable $receiptError) {
                // Preserve the original failure. An unavailable database can leave a running receipt for bounded expiry.
                Log::warning('Provider refresh failure receipt unavailable.', [
                    'attempt_id' => $this->attempt?->id, 'exception_class' => $receiptError::class,
                ]);
            }
        }
    }

    public function finish(): void
    {
        if (! $this->finished) {
            $this->coordinator->finish($this, 'unavailable', 'source_unavailable_or_incomplete');
            $this->finished = true;
        }
    }
}
