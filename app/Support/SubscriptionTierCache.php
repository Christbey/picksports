<?php

namespace App\Support;

use App\Models\SubscriptionTier;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class SubscriptionTierCache
{
    private const NAMESPACE_KEY = 'subscription_tiers:cache_namespace';

    public function defaultTier(): ?SubscriptionTier
    {
        $defaultTierSlug = (string) config('subscriptions.default_tier', 'free');

        return $this->tierBySlug($defaultTierSlug);
    }

    /**
     * @return Collection<int, SubscriptionTier>
     */
    public function activeOrdered(): Collection
    {
        return Cache::remember(
            $this->key('active_ordered'),
            now()->addSeconds($this->ttlSeconds()),
            fn (): Collection => SubscriptionTier::query()->active()->ordered()->get()
        );
    }

    /**
     * @return array<int, string>
     */
    public function allSlugs(): array
    {
        return Cache::remember(
            $this->key('all_slugs'),
            now()->addSeconds($this->ttlSeconds()),
            fn (): array => SubscriptionTier::query()->pluck('slug')->all()
        );
    }

    public function tierBySlug(string $slug): ?SubscriptionTier
    {
        $normalized = trim($slug);
        if ($normalized === '') {
            return null;
        }

        return Cache::remember(
            $this->key("slug:{$normalized}"),
            now()->addSeconds($this->ttlSeconds()),
            fn (): ?SubscriptionTier => SubscriptionTier::query()->where('slug', $normalized)->first()
        );
    }

    public function tierByStripePrice(?string $stripePriceId): ?SubscriptionTier
    {
        $normalized = trim((string) $stripePriceId);
        if ($normalized === '') {
            return null;
        }

        return Cache::remember(
            $this->key('stripe_price:'.md5($normalized)),
            now()->addSeconds($this->ttlSeconds()),
            fn (): ?SubscriptionTier => SubscriptionTier::query()
                ->where('stripe_price_id_monthly', $normalized)
                ->orWhere('stripe_price_id_yearly', $normalized)
                ->first()
        );
    }

    public function bust(): void
    {
        Cache::forever(self::NAMESPACE_KEY, Str::uuid()->toString());
    }

    private function key(string $suffix): string
    {
        return "subscription_tiers:{$this->namespaceToken()}:{$suffix}";
    }

    private function namespaceToken(): string
    {
        return Cache::rememberForever(
            self::NAMESPACE_KEY,
            fn (): string => Str::uuid()->toString()
        );
    }

    private function ttlSeconds(): int
    {
        return max(30, (int) config('subscriptions.cache_ttl_seconds', 300));
    }
}
