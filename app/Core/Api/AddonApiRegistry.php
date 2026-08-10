<?php

declare(strict_types=1);

namespace App\Core\Api;

use Closure;
use InvalidArgumentException;

/**
 * Addons register HTTP route binders here; Core owns versioning + envelope conventions.
 */
final class AddonApiRegistry
{
    /** @var array<string, array{addon: string, version: string, binder: Closure}> */
    private array $routes = [];

    public function register(string $key, string $addonSlug, Closure $binder, string $version = 'v1'): void
    {
        $key = trim($key);
        if ($key === '') {
            throw new InvalidArgumentException('API route key must not be empty.');
        }

        if (isset($this->routes[$key])) {
            throw new InvalidArgumentException("API route key [{$key}] already registered.");
        }

        $this->routes[$key] = [
            'addon' => $addonSlug,
            'version' => $version,
            'binder' => $binder,
        ];
    }

    public function has(string $key): bool
    {
        return isset($this->routes[$key]);
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->routes);
    }

    /**
     * @return list<array{key: string, addon: string, version: string}>
     */
    public function catalog(): array
    {
        $out = [];
        foreach ($this->routes as $key => $meta) {
            $out[] = [
                'key' => $key,
                'addon' => $meta['addon'],
                'version' => $meta['version'],
            ];
        }

        return $out;
    }

    /**
     * Invoke all binders (typically during route boot). Missing addons simply never registered.
     */
    public function bindAll(): void
    {
        foreach ($this->routes as $meta) {
            ($meta['binder'])();
        }
    }
}
