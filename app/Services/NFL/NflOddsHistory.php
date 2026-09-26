<?php

namespace App\Services\NFL;

use App\Models\GameOddsSnapshot;
use App\Models\NFL\Game;
use Illuminate\Database\Eloquent\Builder;

/**
 * One prediction's odds history: count and at most three payloads, loaded once.
 * Create a new instance for each prediction so later refreshes never reuse it.
 */
final class NflOddsHistory
{
    private ?int $snapshotCount = null;

    private ?int $maximumSnapshotId = null;

    /** @var array<int, GameOddsSnapshot|null> */
    private array $snapshots = [];

    public function __construct(private readonly Game $game) {}

    public function count(): int
    {
        if ($this->snapshotCount === null) {
            // Pin the append-only history boundary in the same read as its count.
            $summary = $this->query()->toBase()
                ->selectRaw('COUNT(*) AS snapshot_count, MAX(id) AS maximum_id')
                ->first();
            $this->snapshotCount = (int) $summary->snapshot_count;
            $this->maximumSnapshotId = $summary->maximum_id !== null ? (int) $summary->maximum_id : null;
        }

        return $this->snapshotCount;
    }

    public function first(): ?GameOddsSnapshot
    {
        return $this->at(0);
    }

    public function last(): ?GameOddsSnapshot
    {
        return $this->at($this->count() - 1);
    }

    public function middle(): ?GameOddsSnapshot
    {
        return $this->count() >= 3 ? $this->at(intdiv($this->count(), 2)) : null;
    }

    private function at(int $index): ?GameOddsSnapshot
    {
        $count = $this->count();
        if ($index < 0 || $index >= $count) {
            return null;
        }

        if (! array_key_exists($index, $this->snapshots)) {
            $isLast = $index === $count - 1;
            $direction = $isLast ? 'desc' : 'asc';
            $this->snapshots[$index] = $this->query()
                ->where('id', '<=', $this->maximumSnapshotId)
                ->orderBy('captured_at', $direction)
                // Same-timestamp snapshots previously had undefined ordering.
                ->orderBy('id', $direction)
                ->offset($isLast ? 0 : $index)
                ->first(['id', 'captured_at', 'odds_data']);
        }

        return $this->snapshots[$index];
    }

    /** @return Builder<GameOddsSnapshot> */
    private function query(): Builder
    {
        return GameOddsSnapshot::query()
            ->where('sport', 'nfl')
            ->where('game_table', $this->game->getTable())
            ->where('game_id', $this->game->id);
    }
}
