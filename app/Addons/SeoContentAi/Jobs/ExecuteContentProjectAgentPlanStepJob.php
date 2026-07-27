<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Jobs;

use App\Addons\SeoContentAi\Services\ContentProject\Agent\Planner\ContentProjectAgentPlanExecutor;
use App\Addons\SeoContentAi\Services\SeoDatabaseConnectionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class ExecuteContentProjectAgentPlanStepJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(
        public readonly string $planRef,
    ) {}

    public function handle(
        SeoDatabaseConnectionService $databaseConnection,
        ContentProjectAgentPlanExecutor $executor,
    ): void {
        $databaseConnection->bootstrapLegacySharedConnection();
        $executor->processNext($this->planRef);
    }
}
