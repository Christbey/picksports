<?php

use App\Actions\ESPN\CFB\SyncPlayerInjuries;
use App\Models\CFB\Team;
use App\Services\ESPN\BaseEspnService;

it('distinguishes a successful empty injury feed from failed feeds and missing teams', function () {
    $team = Team::factory()->create();
    $service = new class extends BaseEspnService
    {
        protected const SPORT_KEY = 'cfb';

        public bool $available = true;

        public function getTeamInjuries(string $teamId): ?array
        {
            return $this->available ? ['items' => []] : null;
        }

        public function getRoster(string $teamId): ?array
        {
            return $this->available ? ['athletes' => []] : null;
        }
    };
    $action = new SyncPlayerInjuries($service);
    expect($action->execute((string) $team->espn_id))->toBe(0)
        ->and($action->lastSyncReliable())->toBeTrue();
    $service->available = false;
    expect($action->execute((string) $team->espn_id))->toBe(0)
        ->and($action->lastSyncReliable())->toBeFalse();
    $service->available = true;
    $action->execute((string) $team->espn_id);
    expect($action->execute('missing-team'))->toBe(0)
        ->and($action->lastSyncReliable())->toBeFalse();
});
