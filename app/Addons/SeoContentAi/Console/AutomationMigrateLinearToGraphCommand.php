<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Console;

use App\Addons\SeoContentAi\Automation\BusinessHook\Models\AutomationRule;
use App\Addons\SeoContentAi\Automation\BusinessHook\Models\AutomationRuleEdge;
use App\Addons\SeoContentAi\Automation\BusinessHook\Models\AutomationRuleNode;
use App\Addons\SeoContentAi\Automation\BusinessHook\Support\LinearRuleGraphAdapter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class AutomationMigrateLinearToGraphCommand extends Command
{
    protected $signature = 'automation:migrate-linear-to-graph
        {--dry-run : Preview only}
        {--apply : Persist graph rows and switch workflow_mode}';

    protected $description = 'Optionally migrate linear automation rules to persisted graph nodes/edges.';

    public function handle(LinearRuleGraphAdapter $adapter): int
    {
        $apply = (bool) $this->option('apply');
        $dryRun = (bool) $this->option('dry-run') || ! $apply;

        $rules = AutomationRule::query()
            ->where('workflow_mode', 'linear')
            ->with('actions')
            ->get();

        if ($rules->isEmpty()) {
            $this->info('No linear rules found.');

            return self::SUCCESS;
        }

        foreach ($rules as $rule) {
            $graph = $adapter->toVirtualGraph($rule);
            $this->line("Rule [{$rule->code}] → ".count($graph['nodes']).' nodes, '.count($graph['edges']).' edges');

            if ($dryRun) {
                continue;
            }

            DB::connection('omi_seo_ai')->transaction(function () use ($rule, $graph): void {
                AutomationRuleNode::query()->where('automation_rule_id', $rule->id)->delete();
                AutomationRuleEdge::query()->where('automation_rule_id', $rule->id)->delete();

                foreach ($graph['nodes'] as $node) {
                    AutomationRuleNode::query()->create([
                        'automation_rule_id' => $rule->id,
                        ...$node,
                    ]);
                }

                foreach ($graph['edges'] as $edge) {
                    AutomationRuleEdge::query()->create([
                        'automation_rule_id' => $rule->id,
                        ...$edge,
                    ]);
                }

                $rule->forceFill(['workflow_mode' => 'graph'])->save();
            });
        }

        $this->info($dryRun ? 'Dry run complete.' : 'Migration applied.');

        return self::SUCCESS;
    }
}
