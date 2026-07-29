<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Console;

use App\Addons\SeoContentAi\Jobs\AggregateAgentMetricsJob;
use App\Addons\SeoContentAi\Services\AgentWorkspace\Observability\AgentMetricAggregator;
use App\Addons\SeoContentAi\Services\SeoDatabaseConnectionService;
use Illuminate\Console\Command;

final class AgentMetricsAggregateCommand extends Command
{
    protected $signature = 'agent:metrics:aggregate {--date= : Y-m-d} {--sync : Run inline instead of queue}';

    protected $description = 'Aggregate Agent Workspace metric events into daily buckets (idempotent).';

    public function handle(
        SeoDatabaseConnectionService $databaseConnection,
        AgentMetricAggregator $aggregator,
    ): int {
        $databaseConnection->bootstrapLegacySharedConnection();
        $date = $this->option('date') !== null && $this->option('date') !== ''
            ? (string) $this->option('date')
            : null;

        if ($this->option('sync')) {
            $stats = $aggregator->aggregateDaily($date);
            $this->info('buckets='.$stats['buckets'].' events='.$stats['events']);

            return self::SUCCESS;
        }

        AggregateAgentMetricsJob::dispatch($date);
        $this->info('queued');

        return self::SUCCESS;
    }
}
