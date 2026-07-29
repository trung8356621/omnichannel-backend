<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Jobs;

use App\Addons\SeoContentAi\Services\AgentWorkspace\Observability\AgentRetentionService;
use App\Addons\SeoContentAi\Services\SeoDatabaseConnectionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class ApplyAgentRetentionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly bool $dryRun = false,
    ) {}

    public function handle(
        SeoDatabaseConnectionService $databaseConnection,
        AgentRetentionService $retention,
    ): void {
        $databaseConnection->bootstrapLegacySharedConnection();
        $retention->prune($this->dryRun);
    }
}
