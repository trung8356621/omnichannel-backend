<?php

declare(strict_types=1);

namespace App\Services\ExternalPlugin;

use App\Models\Service;
use Illuminate\Support\Facades\Schema;

final class ExternalPluginRegistry
{
    /** @var array<string, ExternalPluginManifest>|null */
    private ?array $manifests = null;

    /**
     * @return list<ExternalPluginManifest>
     */
    public function all(): array
    {
        return array_values($this->indexedManifests());
    }

    public function resolve(?string $slug): ?ExternalPluginManifest
    {
        $slug = trim((string) $slug);
        if ($slug === '') {
            return null;
        }

        return $this->indexedManifests()[$slug] ?? null;
    }

    public function resolveOrFail(string $slug): ExternalPluginManifest
    {
        $manifest = $this->resolve($slug);
        if ($manifest === null) {
            throw new \InvalidArgumentException("Unknown external plugin slug: {$slug}");
        }

        return $manifest;
    }

    public function defaultManifest(): ?ExternalPluginManifest
    {
        $all = $this->all();

        return $all[0] ?? null;
    }

    /**
     * @return array<string, ExternalPluginManifest>
     */
    private function indexedManifests(): array
    {
        if ($this->manifests !== null) {
            return $this->manifests;
        }

        $this->manifests = [];

        if (! Schema::hasTable('services')) {
            return $this->manifests;
        }

        $services = Service::query()
            ->where('is_active', true)
            ->get(['slug', 'config']);

        foreach ($services as $service) {
            $config = is_array($service->config) ? $service->config : [];
            $plugins = $config['external_plugins'] ?? [];
            if (! is_array($plugins)) {
                continue;
            }

            $addonSlug = trim((string) ($service->slug ?? ''));
            foreach ($plugins as $plugin) {
                if (! is_array($plugin)) {
                    continue;
                }

                $manifest = ExternalPluginManifest::fromAddonConfig($plugin, $addonSlug);
                if ($manifest === null) {
                    continue;
                }

                $this->manifests[$manifest->slug] = $manifest;
            }
        }

        return $this->manifests;
    }
}
