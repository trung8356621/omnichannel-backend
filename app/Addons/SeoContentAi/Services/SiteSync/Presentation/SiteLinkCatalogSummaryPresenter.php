<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\SiteSync\Presentation;

use App\Addons\SeoContentAi\Models\SiteSync\SeoSiteLinkCatalog;
use App\Addons\SeoContentAi\Models\SiteSync\SeoSiteLinkExclusion;
use App\Addons\SeoContentAi\Models\SiteSync\SeoSiteManualLink;
use App\Addons\SeoContentAi\Services\SiteSync\Contracts\SiteSyncSchema;
use App\Models\Site;

/**
 * Domain Settings link catalog summary — never load full WP catalog into form.
 */
final class SiteLinkCatalogSummaryPresenter
{
    /**
     * @return array{wordpress_active: int, manual: int, exclusions: int, inactive: int, label: string}
     */
    public function forSite(Site $site): array
    {
        $siteId = (int) $site->id;
        $wp = SeoSiteLinkCatalog::query()
            ->forSite($siteId)
            ->where('source', SiteSyncSchema::SOURCE_WORDPRESS)
            ->whereNull('inactive_at')
            ->count();
        $manual = SeoSiteManualLink::query()->where('site_id', $siteId)->count();
        $excluded = SeoSiteLinkExclusion::query()->where('site_id', $siteId)->count();
        $inactive = SeoSiteLinkCatalog::query()
            ->forSite($siteId)
            ->whereNotNull('inactive_at')
            ->count();

        return [
            'wordpress_active' => $wp,
            'manual' => $manual,
            'exclusions' => $excluded,
            'inactive' => $inactive,
            'label' => sprintf(
                'WordPress active: %d · Manual: %d · Exclusions: %d · Inactive/deleted: %d. Effective = WP + Manual − Exclusions. Đồng bộ lại qua nút «Đồng bộ & kiểm tra website».',
                $wp,
                $manual,
                $excluded,
                $inactive,
            ),
        ];
    }
}
