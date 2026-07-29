<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\SiteSync\Capability;

use App\Addons\SeoContentAi\Models\SiteSync\SeoSiteCapability;
use App\Addons\SeoContentAi\Services\SiteSync\Contracts\CapabilityManifestData;
use App\Addons\SeoContentAi\Services\SiteSync\Contracts\SiteSyncSchema;
use App\Models\Site;

final class SiteCapabilityResolver
{
    public function store(Site $site, CapabilityManifestData $manifest): SeoSiteCapability
    {
        $row = SeoSiteCapability::query()->updateOrCreate(
            ['site_id' => (int) $site->id],
            [
                'schema_version' => $manifest->schema,
                'bridge_version' => $manifest->bridgeVersion,
                'site_url' => $manifest->siteUrl,
                'manifest' => $manifest->toArray(),
                'detected_at' => now(),
            ],
        );

        app(\App\Addons\SeoContentAi\Services\SiteSync\Cutover\SiteSyncProviderTimelineService::class)
            ->syncFromCapability($site);

        return $row;
    }

    public function forSite(Site $site): ?CapabilityManifestData
    {
        if (! \App\Addons\SeoContentAi\Services\SiteSync\Support\SiteSyncInfrastructure::hasTable('seo_site_capabilities')) {
            return null;
        }

        $row = SeoSiteCapability::query()->where('site_id', (int) $site->id)->first();
        if ($row === null || ! is_array($row->manifest)) {
            return null;
        }

        try {
            return CapabilityManifestData::fromArray($row->manifest);
        } catch (\Throwable) {
            return null;
        }
    }

    public function isAvailable(Site $site, string $capability): bool
    {
        return $this->forSite($site)?->isAvailable($capability) ?? false;
    }

    public function provider(Site $site, string $capability): ?string
    {
        return $this->forSite($site)?->provider($capability);
    }

    /**
     * @return list<string>
     */
    public function missingCapabilities(Site $site): array
    {
        $manifest = $this->forSite($site);
        if ($manifest === null) {
            return SiteSyncSchema::CAPABILITY_KEYS;
        }

        $missing = [];
        foreach (SiteSyncSchema::CAPABILITY_KEYS as $key) {
            if (! $manifest->isAvailable($key)) {
                $missing[] = $key;
            }
        }

        return $missing;
    }
}
