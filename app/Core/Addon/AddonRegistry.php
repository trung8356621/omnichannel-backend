<?php

declare(strict_types=1);

namespace App\Core\Addon;

/**
 * In-memory registry of discovered / enabled addon manifests.
 */
final class AddonRegistry
{
    /** @var array<string, AddonManifest> */
    private array $manifests = [];

    /** @var array<string, true> */
    private array $enabled = [];

    public function put(AddonManifest $manifest): void
    {
        $this->manifests[$manifest->slug] = $manifest;
    }

    /**
     * @param  list<AddonManifest>  $manifests
     */
    public function replaceAll(array $manifests): void
    {
        $this->manifests = [];
        foreach ($manifests as $manifest) {
            $this->put($manifest);
        }
    }

    public function get(string $slug): ?AddonManifest
    {
        return $this->manifests[$slug] ?? null;
    }

    public function has(string $slug): bool
    {
        return isset($this->manifests[$slug]);
    }

    /**
     * @return list<AddonManifest>
     */
    public function all(): array
    {
        return array_values($this->manifests);
    }

    /**
     * @return list<string>
     */
    public function slugs(): array
    {
        return array_keys($this->manifests);
    }

    public function markEnabled(string $slug): void
    {
        $this->enabled[$slug] = true;
    }

    public function isEnabled(string $slug): bool
    {
        return isset($this->enabled[$slug]);
    }

    /**
     * @return list<string>
     */
    public function enabledSlugs(): array
    {
        return array_keys($this->enabled);
    }
}
