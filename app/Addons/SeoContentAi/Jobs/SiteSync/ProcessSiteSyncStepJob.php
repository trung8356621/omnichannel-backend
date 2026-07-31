<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Jobs\SiteSync;

use App\Addons\SeoContentAi\Services\ArticleWpSyncQueueService;
use App\Addons\SeoContentAi\Services\SiteSync\Orchestration\SiteSyncStepRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class ProcessSiteSyncStepJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $uniqueFor = 900;

    public function __construct(
        public readonly int $runId,
    ) {
        $this->onQueue(ArticleWpSyncQueueService::QUEUE_NAME);
    }

    public function uniqueId(): string
    {
        return 'site-sync-step:'.$this->runId;
    }

    public function handle(SiteSyncStepRunner $runner): void
    {
        $runner->runNext($this->runId, true);
    }
}
