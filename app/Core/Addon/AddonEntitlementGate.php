<?php

declare(strict_types=1);

namespace App\Core\Addon;

/**
 * Entitlement hook placeholder — Core does not enforce billing here yet.
 */
final class AddonEntitlementGate
{
    /**
     * @param  callable(string): bool|null  $resolver
     */
    public function __construct(
        private mixed $resolver = null,
    ) {}

    public function allows(string $addonSlug): bool
    {
        if (! (bool) config('addons.entitlement.enabled', false)) {
            return true;
        }

        if (! is_callable($this->resolver)) {
            return true;
        }

        return (bool) ($this->resolver)($addonSlug);
    }
}
