<?php

declare(strict_types=1);

namespace App\Core\Automation;

use Closure;
use InvalidArgumentException;

/**
 * Automation Runtime (Core) — addons only register triggers/actions.
 */
final class TriggerRegistry
{
    /** @var array<string, array{addon: string, factory: Closure}> */
    private array $triggers = [];

    public function register(string $id, string $addonSlug, Closure $factory): void
    {
        $id = trim($id);
        if ($id === '' || isset($this->triggers[$id])) {
            throw new InvalidArgumentException("Invalid or duplicate trigger [{$id}].");
        }

        $this->triggers[$id] = ['addon' => $addonSlug, 'factory' => $factory];
    }

    public function has(string $id): bool
    {
        return isset($this->triggers[$id]);
    }

    /**
     * @return list<string>
     */
    public function ids(): array
    {
        return array_keys($this->triggers);
    }

    public function ownerOf(string $id): ?string
    {
        return $this->triggers[$id]['addon'] ?? null;
    }

    public function make(string $id): mixed
    {
        if (! isset($this->triggers[$id])) {
            return null;
        }

        return ($this->triggers[$id]['factory'])();
    }
}
