<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Jobs\SiteSync;

use App\Addons\SeoContentAi\Services\ArticleWpSyncQueueService;
use App\Addons\SeoContentAi\Services\SiteSync\Inbound\SiteSyncDeltaEventIngestor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class ProcessSiteSyncInboundEventJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public function __construct(
        public readonly int $eventId,
    ) {
        $this->onQueue(ArticleWpSyncQueueService::QUEUE_NAME);
    }

    public function handle(SiteSyncDeltaEventIngestor $ingestor): void
    {
        app(\App\Addons\SeoContentAi\Services\SiteSync\Observability\SiteSyncHeartbeatService::class)
            ->touch('queue', ['job' => 'ProcessSiteSyncInboundEventJob', 'event_id' => $this->eventId]);
        $ingestor->process($this->eventId);
    }
}
