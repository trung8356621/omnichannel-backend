<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Console;

use App\Addons\SeoContentAi\Services\AgentWorkspace\Automation\Services\AgentAutomationDispatcher;
use App\Addons\SeoContentAi\Services\SeoDatabaseConnectionService;
use Illuminate\Console\Command;

/**
 * Dispatch due Agent Workspace automations (occurrence claim + queue only).
 */
final class DispatchDueAgentAutomationsCommand extends Command
{
    protected $signature = 'agent:automations:dispatch-due {--limit=100 : Max automations to claim}';

    protected $description = 'Claim due Agent Workspace automations and dispatch RunAgentAutomation jobs.';

    public function handle(
        SeoDatabaseConnectionService $databaseConnection,
        AgentAutomationDispatcher $dispatcher,
    ): int {
        $databaseConnection->bootstrapLegacySharedConnection();
        $stats = $dispatcher->dispatchDue(max(1, (int) $this->option('limit')));
        $this->info(sprintf(
            'claimed=%d dispatched=%d skipped=%d',
            $stats['claimed'],
            $stats['dispatched'],
            $stats['skipped'],
        ));

        return self::SUCCESS;
    }
}
