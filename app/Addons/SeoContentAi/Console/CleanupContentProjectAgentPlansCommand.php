<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Console;

use App\Addons\SeoContentAi\Models\ContentProjectAgentApproval;
use App\Addons\SeoContentAi\Models\ContentProjectAgentPlan;
use App\Addons\SeoContentAi\Models\ContentProjectAgentPlanStep;
use App\Addons\SeoContentAi\Services\ContentProject\Agent\Planner\AgentApprovalStatus;
use App\Addons\SeoContentAi\Services\ContentProject\Agent\Planner\AgentPlanStatus;
use App\Addons\SeoContentAi\Services\ContentProject\Agent\Planner\ContentProjectAgentApprovalService;
use Illuminate\Console\Command;

final class CleanupContentProjectAgentPlansCommand extends Command
{
    protected $signature = 'seo:content-project:cleanup-agent-plans';

    protected $description = 'Retention cleanup for completed/failed agent plans and approvals';

    public function handle(ContentProjectAgentApprovalService $approvals): int
    {
        $planDays = (int) config('seo-content-ai.content_project_agent.retention.plan_days', 60);
        $approvalDays = (int) config('seo-content-ai.content_project_agent.retention.approval_days', 30);

        $planCutoff = now()->subDays(max(1, $planDays));
        $approvalCutoff = now()->subDays(max(1, $approvalDays));

        $expiredApprovals = $approvals->expireStale();

        $planIds = ContentProjectAgentPlan::query()
            ->whereIn('status', AgentPlanStatus::terminal())
            ->where('updated_at', '<', $planCutoff)
            ->pluck('id')
            ->all();

        if ($planIds !== []) {
            ContentProjectAgentPlanStep::query()->whereIn('plan_id', $planIds)->delete();
        }

        $deletedPlans = $planIds !== []
            ? ContentProjectAgentPlan::query()->whereIn('id', $planIds)->delete()
            : 0;

        $deletedApprovals = ContentProjectAgentApproval::query()
            ->whereIn('status', [AgentApprovalStatus::APPROVED, AgentApprovalStatus::REJECTED, AgentApprovalStatus::EXPIRED, AgentApprovalStatus::CANCELLED])
            ->where('updated_at', '<', $approvalCutoff)
            ->delete();

        $this->info("Expired {$expiredApprovals} pending approval(s).");
        $this->info("Deleted {$deletedPlans} plan(s) and {$deletedApprovals} approval record(s).");

        return self::SUCCESS;
    }
}
