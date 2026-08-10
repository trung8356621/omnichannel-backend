<?php

declare(strict_types=1);

namespace App\Core\Capability;

use InvalidArgumentException;

/**
 * Core capability registry — addons register, never import peers.
 */
final class CapabilityRegistry
{
    /** @var array<string, object> */
    private array $capabilities = [];

    /** @var array<string, string> slug of owner addon */
    private array $owners = [];

    public function register(string $id, object $capability, string $ownerSlug = ''): void
    {
        $id = trim($id);
        if ($id === '') {
            throw new InvalidArgumentException('Capability id must not be empty.');
        }

        if (isset($this->capabilities[$id])) {
            throw new InvalidArgumentException("Capability [{$id}] already registered.");
        }

        $this->capabilities[$id] = $capability;
        if ($ownerSlug !== '') {
            $this->owners[$id] = $ownerSlug;
        }
    }

    public function get(string $id): ?object
    {
        return $this->capabilities[$id] ?? null;
    }

    /**
     * @template T of object
     * @param  class-string<T>  $contract
     * @return T|null
     */
    public function getAs(string $id, string $contract): ?object
    {
        $cap = $this->get($id);
        if ($cap === null) {
            return null;
        }

        if (! $cap instanceof $contract) {
            return null;
        }

        return $cap;
    }

    public function has(string $id): bool
    {
        return isset($this->capabilities[$id]);
    }

    public function ownerOf(string $id): ?string
    {
        return $this->owners[$id] ?? null;
    }

    /**
     * @return list<string>
     */
    public function ids(): array
    {
        return array_keys($this->capabilities);
    }

    /**
     * Soft lookup — missing optional capability returns null, never throws.
     */
    public function optional(string $id): ?object
    {
        return $this->get($id);
    }
}
