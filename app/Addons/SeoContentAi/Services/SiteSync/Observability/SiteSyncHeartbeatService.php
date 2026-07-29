<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\SiteSync\Observability;

use App\Addons\SeoContentAi\Models\SiteSync\SeoSiteSyncHeartbeat;

final class SiteSyncHeartbeatService
{
    public function touch(string $channel, array $meta = []): void
    {
        SeoSiteSyncHeartbeat::query()->updateOrCreate(
            ['channel' => $channel],
            [
                'last_seen_at' => now(),
                'meta' => $meta,
            ],
        );
    }
}
