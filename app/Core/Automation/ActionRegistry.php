<?php

declare(strict_types=1);

namespace App\Core\Automation;

use Closure;
use InvalidArgumentException;

final class ActionRegistry
{
    /** @var array<string, array{addon: string, factory: Closure}> */
    private array $actions = [];

    public function register(string $id, string $addonSlug, Closure $factory): void
    {
        $id = trim($id);
        if ($id === '' || isset($this->actions[$id])) {
            throw new InvalidArgumentException("Invalid or duplicate action [{$id}].");
        }

        $this->actions[$id] = ['addon' => $addonSlug, 'factory' => $factory];
    }

    public function has(string $id): bool
    {
        return isset($this->actions[$id]);
    }

    /**
     * @return list<string>
     */
    public function ids(): array
    {
        return array_keys($this->actions);
    }

    public function ownerOf(string $id): ?string
    {
        return $this->actions[$id]['addon'] ?? null;
    }

    public function make(string $id): mixed
    {
        if (! isset($this->actions[$id])) {
            return null;
        }

        return ($this->actions[$id]['factory'])();
    }
}
