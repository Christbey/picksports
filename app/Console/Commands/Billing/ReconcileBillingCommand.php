<?php

namespace App\Console\Commands\Billing;

use App\Services\Billing\BillingJobHandoff;
use App\Services\Billing\BillingOperatorAdmission;
use Illuminate\Console\Command;
use Throwable;

class ReconcileBillingCommand extends Command
{
    protected $signature = 'billing:reconcile
        {--state=* : Explicit local billing state IDs}
        {--user=* : Explicit local user IDs; include their bounded local subscription inventory}
        {--subscription=* : Explicit local subscription primary keys}
        {--reason= : Required plain-text audit reason when applying, maximum255 characters}
        {--reset-exhausted : Explicitly audit and reset retry exhaustion, never integrity-conflict holds}
        {--resume-classified-role-origin : Resume only a classified entitlement_role_origin_unknown hold after current validation}
        {--resume-entitlement-admission : Resume only entitlement_provenance_required after configured current admission validation}
        {--apply : Admit immutable operator intent and attempt asynchronous handoff; otherwise preview only}';

    protected $description = 'Preview or admit bounded explicit billing recovery without inventing Stripe events or fetching in this process';

    public function handle(BillingOperatorAdmission $operator, BillingJobHandoff $jobs): int
    {
        try {
            $scopes = [];
            foreach (['state', 'user', 'subscription'] as $name) {
                $scopes[$name] = [];
                foreach ($this->option($name) as $raw) {
                    $id = is_string($raw) ? filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
                    if ($id === false) {
                        throw new \InvalidArgumentException('Scope IDs must be positive local integers.');
                    }
                    $scopes[$name][] = $id;
                }
            }
            $reason = $this->option('reason');
            if ($this->option('apply') && (! is_string($reason) || $reason === '' || mb_strlen($reason) > 255 || preg_match('/[\x00-\x1f\x7f]/', $reason))) {
                throw new \InvalidArgumentException('Apply requires a plain-text reason of1 through255 characters.');
            }
            // Validate EVERY selected scope before the first customer transaction.
            $plans = $operator->plan($scopes['state'], $scopes['user'], $scopes['subscription'], (bool) $this->option('reset-exhausted'), (bool) $this->option('resume-classified-role-origin'), (bool) $this->option('resume-entitlement-admission'));
        } catch (Throwable) {
            $this->error('Scope rejected; no operator intent admitted. Check exact IDs/context, live leases, target caps, reason and attention state.');

            return self::INVALID;
        }
        if (! $this->option('apply')) {
            $this->line(json_encode(['dry_run' => true, 'customers' => $plans], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }
        $admitted = [];
        foreach ($plans as $plan) {
            try {
                $intent = $operator->admit($plan, $reason);
                $admitted[] = ['state_id' => $intent->reconciliation_id, 'intent_id' => $intent->id];
                $jobs->dispatch((int) $intent->reconciliation_id);
            } catch (Throwable) {
                $this->error('A customer changed after preflight; stopped. Earlier customer admissions remain durable (per-customer transactions).');
                $this->line(json_encode(['partial' => true, 'admitted' => $admitted], JSON_THROW_ON_ERROR));

                return self::FAILURE;
            }
        }
        $this->line(json_encode(['partial' => false, 'admitted' => $admitted], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
