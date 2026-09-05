<?php

namespace App\Contracts\DeveloperPlatform;

/**
 * Integration seam for a future Stripe Billing Meter implementation.
 *
 * Intentionally unbound: constructing meter batches must never make an
 * external billing call. A production adapter requires a separate rollout.
 */
interface StripeMeterAdapter extends BillingMeterAdapter {}
