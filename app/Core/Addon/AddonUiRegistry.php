<?php

declare(strict_types=1);

namespace App\Core\Addon;

use Closure;
use InvalidArgumentException;

/**
 * Addon UI registry — Filament pages / React slots register here without Core hard-coding them.
 */
final class AddonUiRegistry
{
    /** @var array<string, array{addon: string, slot: string, binder: Closure}> */
    private array $entries = [];

    public function register(string $key, string $addonSlug, string $slot, Closure $binder): void
    {
        $key = trim($key);
        if ($key === '' || isset($this->entries[$key])) {
            throw new InvalidArgumentException("Invalid or duplicate UI entry [{$key}].");
        }

        $this->entries[$key] = [
            'addon' => $addonSlug,
            'slot' => $slot,
            'binder' => $binder,
        ];
    }

    public function has(string $key): bool
    {
        return isset($this->entries[$key]);
    }

    /**
     * @return list<array{key: string, addon: string, slot: string}>
     */
    public function catalog(?string $slot = null): array
    {
        $out = [];
        foreach ($this->entries as $key => $meta) {
            if ($slot !== null && $meta['slot'] !== $slot) {
                continue;
            }
            $out[] = [
                'key' => $key,
                'addon' => $meta['addon'],
                'slot' => $meta['slot'],
            ];
        }

        return $out;
    }

    public function bindAll(?string $slot = null): void
    {
        foreach ($this->entries as $meta) {
            if ($slot !== null && $meta['slot'] !== $slot) {
                continue;
            }
            ($meta['binder'])();
        }
    }
}
