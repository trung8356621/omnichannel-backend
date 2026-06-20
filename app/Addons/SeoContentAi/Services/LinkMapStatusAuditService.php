<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Jobs\AuditLinkStatusJob;
use App\Addons\SeoContentAi\Models\SeoLinkMap;

final class LinkMapStatusAuditService
{
    public function queueLinkMap(SeoLinkMap $linkMap, int $siteId): void
    {
        $linkMapId = (int) ($linkMap->id ?? 0);
        if ($linkMapId <= 0 || $siteId <= 0) {
            return;
        }

        AuditLinkStatusJob::dispatch($linkMapId, $siteId);
    }

    public function queueDomainAudit(int $siteId): int
    {
        if ($siteId <= 0) {
            return 0;
        }

        $queued = 0;

        SeoLinkMap::query()
            ->whereHas('sourceArticle', static fn ($query) => $query->where('site_id', $siteId))
            ->select(['id'])
            ->orderBy('id')
            ->chunkById(200, function ($maps) use ($siteId, &$queued): void {
                foreach ($maps as $map) {
                    if (! $map instanceof SeoLinkMap) {
                        continue;
                    }

                    AuditLinkStatusJob::dispatch((int) $map->id, $siteId);
                    $queued++;
                }
            });

        return $queued;
    }
}
