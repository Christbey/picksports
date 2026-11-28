<?php

namespace App\Services\Sports\Elo;

use App\Casts\UtcEloDateTime;
use App\Exceptions\EloAdmissionRefused;
use App\Models\SportEvent;
use App\Services\Predictions\CanonicalPayloadHasher;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/** Captures exact managed values and their validated source closure, never a boolean followed by a reread. */
class EloInputAdmission
{
    public function __construct(private readonly EloAdmissionMode $mode, private readonly EloSportRegistry $registry,
        private readonly EloWriterProtocol $protocol, private readonly EloSourceContext $sources,
        private readonly EloWriteTargetIdentity $targets, private readonly CanonicalPayloadHasher $hasher) {}

    public function teams(string $sport, Model $caller, ?CarbonImmutable $expectedCutoff = null, ?SportEvent $expectedEvent = null): ?array
    {
        $connection = $caller->getConnection();
        if (! $this->mode->managed($sport, $connection)) {
            return null;
        }
        $definition = $this->registry->resolve($sport, 'team');
        $this->protocol->requireCapability($sport);
        if (! $caller->exists || get_class($caller) !== $definition['game_model']) {
            $this->refuse('input_game_identity_invalid');
        }
        if ($sport === 'mlb') {
            $this->refuse('typed_mlb_input_admission_required'); // Pitcher/history adapters are a separate required slice.
        }

        $clockPolicy = app(EloSqlClockPolicy::class);
        $clock = $clockPolicy->capture($connection);

        return $connection->transaction(function () use ($connection, $sport, $caller, $definition, $expectedCutoff, $expectedEvent, $clockPolicy, $clock): array {
            $clockPolicy->assertUnchanged($clock, $connection);
            // An event lock may precede the Elo gate; supported Elo writers never request event locks.
            // This preserves publication's event -> game order, and gate -> sorted games for Elo.
            $event = $caller->sport_event_id === null ? null : SportEvent::on($connection->getName())
                ->whereKey($caller->sport_event_id)->lockForUpdate()->first();
            try {
                $cutoff = (new UtcEloDateTime)->observed($caller->getRawOriginal('game_date'), $caller->getRawOriginal('game_time'));
            } catch (\Throwable) {
                $this->refuse('input_kickoff_unknown');
            }
            if ($expectedEvent !== null && ($event === null || (int) $event->id !== (int) $expectedEvent->id
                || $event->neutral_site !== $expectedEvent->neutral_site
                || $event->starts_at?->getTimestamp() !== $expectedEvent->starts_at?->getTimestamp())) {
                $this->refuse('input_event_context_changed');
            }
            if ($event !== null && (! in_array($event->status, ['STATUS_SCHEDULED', 'STATUS_DELAYED'], true)
                || $event->sport !== $sport || (int) $event->season !== (int) $caller->season)) {
                $this->refuse('input_event_identity_changed');
            }
            if ($expectedCutoff !== null && ! $cutoff->equalTo($expectedCutoff)) {
                $this->refuse('input_cutoff_changed');
            }
            $this->protocol->lockGate($connection, $sport, $definition);
            $this->sources->beginObservation();
            $participants = ['team' => [(int) $caller->home_team_id, (int) $caller->away_team_id]];
            if (min($participants['team']) < 1 || count(array_unique($participants['team'])) !== 2) {
                $this->refuse('input_team_identity_invalid');
            }
            $through = [$cutoff->format('Y-m-d H:i:s.u'), (int) $caller->id];
            $locked = $this->sources->lockGames($connection, $sport, $definition, $participants, [(int) $caller->id],
                (int) $caller->season, [(int) $caller->season], (int) $caller->id, $through);
            $game = $locked['games'][$caller->id] ?? null;
            if ($game === null || ! in_array($game->status, ['STATUS_SCHEDULED', 'STATUS_DELAYED'], true)
                || $cutoff->lessThanOrEqualTo(CarbonImmutable::now('UTC'))) {
                $this->refuse('input_target_not_open');
            }
            foreach (['id', 'espn_event_id', 'sport_event_id', 'season', 'season_type', 'home_team_id', 'away_team_id', 'game_date', 'game_time', 'neutral_site'] as $field) {
                $current = match ($field) {
                    'neutral_site' => $this->neutral($game->getRawOriginal($field)),
                    'game_date' => $this->dateIdentity($game->getRawOriginal($field)),
                    default => (string) $game->getRawOriginal($field),
                };
                $expected = match ($field) {
                    'neutral_site' => $this->neutral($caller->getRawOriginal($field)),
                    'game_date' => $this->dateIdentity($caller->getRawOriginal($field)),
                    default => (string) $caller->getRawOriginal($field),
                };
                if ($current !== $expected) {
                    $this->refuse('input_target_changed_'.$field);
                }
            }
            if ($event !== null && ((int) $game->sport_event_id !== (int) $event->id || (string) $event->season_type !== (string) $game->season_type)) {
                $this->refuse('input_event_identity_changed');
            }
            $ids = $participants['team'];
            sort($ids, SORT_NUMERIC);
            $entities = $definition['entity_model']::on($connection->getName())->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            if ($entities->count() !== 2) {
                $this->refuse('input_team_unavailable');
            }
            $heads = [];
            $values = [];
            $receipts = [];
            foreach ($entities as $entity) {
                $head = $this->protocol->lockHead($connection, $sport, 'team', $entity, $definition);
                if ($head->season !== (int) $game->season || ($head->last_played_at !== null && $head->last_played_at->greaterThanOrEqualTo($cutoff))) {
                    $this->refuse('input_head_outside_cutoff_or_season');
                }
                $this->sources->verifyHead($connection, $head, $locked);
                $this->sources->verifyPending($connection, $head, $definition, $locked, $game, $through);
                $heads[] = $head;
                $values[(int) $entity->id] = $head->rating;
                $receipts[(int) $entity->id] = $head->only(['id', 'initialization_id', 'last_effect_id', 'last_game_id', 'generation', 'season', 'rating']);
            }
            $this->sources->verifyDependencies($connection, $sport, $definition, $locked, $heads);
            $captured = CarbonImmutable::now('UTC');
            $clockPolicy->assertUnchanged($clock, $connection);

            return ['values' => $values, 'evidence' => ['policy' => 'guarded-team-input-closure-v2-utc-source', 'sport' => $sport,
                'game_id' => (int) $game->id, 'calculation_version' => $definition['version'], 'configuration_hash' => $definition['configuration_hash'],
                'admitted_at_utc' => $captured->format('Y-m-d\TH:i:s.u\Z'), 'cutoff_at_utc' => $cutoff->format('Y-m-d\TH:i:s.u\Z'),
                'target_fingerprint' => $this->targets->describe($connection)['fingerprint'], 'heads' => $receipts,
                'dependency_plan_hash' => $this->hasher->hash($locked['dependency_plan'])]];
        });
    }

    private function dateIdentity(mixed $value): string
    {
        $text = (string) $value;
        if (strlen($text) > 26 || preg_match('/\A([0-9]{4}-[0-9]{2}-[0-9]{2})(?: ((?:[01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9])(?:\.([0-9]{1,6}))?)?\z/', $text, $parts) !== 1) {
            $this->refuse('input_target_date_invalid');
        }
        try {
            if (CarbonImmutable::createFromFormat('!Y-m-d', $parts[1], 'UTC')->format('Y-m-d') !== $parts[1]) {
                $this->refuse('input_target_date_invalid');
            }
        } catch (\Throwable) {
            $this->refuse('input_target_date_invalid');
        }
        // DATE storage and an ORM datetime cast may differ only by an all-zero suffix.
        // This is calendar identity, never a replacement for the separately required kickoff.
        if (! isset($parts[2]) || ($parts[2] === '00:00:00' && trim($parts[3] ?? '', '0') === '')) {
            return $parts[1];
        }

        return $text; // Non-midnight times remain exact identity facts; never discard them.
    }

    private function neutral(mixed $value): ?bool
    {
        if ($value === null) {
            return null;
        }
        if (! in_array($value, [false, true, 0, 1, '0', '1'], true)) {
            $this->refuse('input_target_neutral_invalid');
        }

        return (bool) $value;
    }

    private function refuse(string $reason): never
    {
        throw new EloAdmissionRefused($reason, [], EloEligibilityPolicy::LIMITS);
    }
}
