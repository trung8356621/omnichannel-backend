<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Providers\Serp\SerpRankProviderRegistry;
use App\Addons\SeoContentAi\Support\ApiConnectionProviders;
use App\Addons\SeoContentAi\Support\SerpProviderKeys;

final class SerpProviderCapabilityService
{
    public function __construct(
        private readonly SerpRankProviderRegistry $registry,
        private readonly SeoProviderCapabilityResolver $capabilityResolver,
    ) {}

    /**
     * @return array{rank: bool, allintitle: bool, search_volume: bool, search_volume_configured: bool}
     */
    public function resolveForUser(int $userId, string $provider, KeywordSearchVolumeService $volumeService): array
    {
        return $this->capabilityResolver->legacyToolbarCapabilities($userId, $provider);
    }

    /**
     * @param  list<string>  $requested
     * @return list<string>
     */
    public function filterDispatchableMetrics(int $userId, string $provider, array $requested, KeywordSearchVolumeService $volumeService): array
    {
        $allowed = [];

        foreach ($requested as $metric) {
            if ($this->capabilityResolver->canDispatchMetric($userId, $provider, $metric)) {
                $allowed[] = $metric;
            }

            if ($metric === 'search_volume'
                && $provider !== ApiConnectionProviders::DATAFORSEO
                && $volumeService->isConfiguredForUser($userId)) {
                $allowed[] = 'search_volume';
            }
        }

        return array_values(array_unique($allowed));
    }
}
