<?php

declare(strict_types=1);

namespace App\Addons\WpHeadless\Jobs;

use App\Addons\WpHeadless\Services\WpHeadlessSyncService;
use App\Models\Site;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Chạy đồng bộ sync-site-data một bước (step 1–4) trong queue để tránh timeout khi WordPress gọi.
 * Kết quả lưu vào cache để WordPress poll sync-site-data/status.
 */
class SyncSiteDataStepJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const CACHE_PREFIX = 'wp_headless_sync_';
    public const CACHE_TTL_SECONDS = 300;

    public function __construct(
        public int $siteId,
        public int $step
    ) {
        $this->onQueue(config('queue.connections.' . config('queue.default') . '.queue', 'default'));
        $this->timeout = 300;
    }

    public function handle(WpHeadlessSyncService $syncService): void
    {
        $key = self::CACHE_PREFIX . $this->siteId . '_' . $this->step;

        try {
            $site = Site::find($this->siteId);
            if (!$site) {
                $this->storeResult($key, 'failed', ['success' => false, 'message' => 'Site not found.']);
                return;
            }

            $result = $syncService->syncStep($site, $this->step);
            $this->storeResult($key, $result['success'] ? 'completed' : 'failed', $result);
        } catch (\Throwable $e) {
            Log::error('SyncSiteDataStepJob failed', [
                'site_id' => $this->siteId,
                'step' => $this->step,
                'message' => $e->getMessage(),
            ]);
            $this->storeResult($key, 'failed', [
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function storeResult(string $key, string $status, array $result): void
    {
        Cache::put($key, [
            'status' => $status,
            'result' => $result,
        ], self::CACHE_TTL_SECONDS);
    }
}
