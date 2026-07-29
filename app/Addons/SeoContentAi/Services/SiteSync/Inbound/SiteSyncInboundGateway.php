<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\SiteSync\Inbound;

use App\Addons\SeoContentAi\Models\SiteSync\SeoSiteSyncBatch;
use App\Addons\SeoContentAi\Services\SiteSync\Contracts\SiteSyncBatchData;
use App\Addons\SeoContentAi\Services\SiteSync\Contracts\SiteSyncSchema;
use App\Addons\SeoContentAi\Services\SiteSync\Orchestration\SiteSyncFeatureFlags;
use App\Addons\SeoContentAi\Services\SiteSync\Reconciliation\SiteSyncBatchReconciler;
use App\Addons\SeoContentAi\Services\SyncDomainContentService;
use App\Models\Site;
use App\Support\RuntimeLogger;

/**
 * HTTP inbound for WP snapshot-callback + compat push.
 */
final class SiteSyncInboundGateway
{
    public function __construct(
        private readonly SiteSyncStagingWriter $staging,
        private readonly SiteSyncBatchReconciler $reconciler,
        private readonly SiteSyncFeatureFlags $flags,
        private readonly SyncDomainContentService $legacySync,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{success: bool, message: string, batch_id?: int, counters?: array<string, int>}
     */
    public function ingestSnapshotCallback(Site $site, array $payload): array
    {
        try {
            if (! isset($payload['schema'])) {
                $payload['schema'] = SiteSyncSchema::VERSION;
            }
            if (! isset($payload['mode'])) {
                $payload['mode'] = SiteSyncSchema::MODE_DELTA;
            }

            $batch = SiteSyncBatchData::fromArray($payload);
            $staged = $this->staging->stage($site, $batch);
            $counters = $this->reconciler->apply($site, $staged);

            return [
                'success' => true,
                'message' => 'Snapshot callback applied.',
                'batch_id' => (int) $staged->id,
                'counters' => $counters,
            ];
        } catch (\Throwable $e) {
            RuntimeLogger::warning('site_sync.snapshot_callback_failed', [
                'site_id' => $site->id,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * TTL-bound compatibility: stage V2 when enabled, always keep legacy import for cutover.
     *
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    public function ingestCompatPush(Site $site, array $items): array
    {
        $legacy = $this->legacySync->importPushedItems($site, $items);

        if (! $this->flags->compatPushEnabled()) {
            return $legacy;
        }

        try {
            $staged = $this->staging->stageLegacyPushItems($site, $items);
            $v2Counters = $this->reconciler->applyLinksKeywordsScoresOnly($site, $staged);
            $legacy['site_sync_v2'] = [
                'compat' => true,
                'batch_id' => (int) $staged->id,
                'counters' => $v2Counters,
            ];
        } catch (\Throwable $e) {
            RuntimeLogger::warning('site_sync.compat_push_failed', [
                'site_id' => $site->id,
                'error' => $e->getMessage(),
            ]);
            $legacy['site_sync_v2'] = [
                'compat' => true,
                'error' => $e->getMessage(),
            ];
        }

        return $legacy;
    }

    public function markApplied(SeoSiteSyncBatch $batch): void
    {
        if ($batch->applied_at === null) {
            $batch->forceFill(['applied_at' => now()])->save();
        }
    }
}
