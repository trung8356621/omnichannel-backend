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
            $addonSlug = trim((string) ($service->slug ?? ''));
            foreach ($this->externalPluginsForService($service) as $plugin) {
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

    /**
     * @return list<array<string, mixed>>
     */
    private function externalPluginsForService(Service $service): array
    {
        $config = is_array($service->config) ? $service->config : [];
        $fromConfig = $config['external_plugins'] ?? null;
        if (is_array($fromConfig) && $fromConfig !== []) {
            return $fromConfig;
        }

        $addonMeta = $this->readAddonJsonForSlug((string) $service->slug);
        if ($addonMeta === null) {
            return [];
        }

        $fromFile = $addonMeta['external_plugins'] ?? [];

        return is_array($fromFile) ? $fromFile : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readAddonJsonForSlug(string $serviceSlug): ?array
    {
        $serviceSlug = trim($serviceSlug);
        if ($serviceSlug === '') {
            return null;
        }

        $folderName = str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $serviceSlug)));
        $jsonPath = app_path("Addons/{$folderName}/addon.json");
        if (! is_file($jsonPath)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($jsonPath), true);

        return is_array($decoded) ? $decoded : null;
    }
}
