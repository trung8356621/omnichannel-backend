<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Pages;

use App\Addons\SeoContentAi\Models\ContentProjectAgentApproval;
use App\Addons\SeoContentAi\Models\ContentProjectAgentPlan;
use App\Addons\SeoContentAi\Models\ContentProjectOperation;
use App\Addons\SeoContentAi\Models\SeoProject;
use App\Addons\SeoContentAi\Services\ContentProject\Agent\AgentExecutionContext;
use App\Addons\SeoContentAi\Services\ContentProject\Agent\Planner\ContentProjectAgentPlanApplicationService;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ContentProjectActionResultNotifier;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ContentProjectPublicRef;
use App\Addons\SeoContentAi\Services\ContentProject\ContentProjectTimelineService;
use App\Addons\SeoContentAi\Services\ContentProject\Operations\ContentProjectAiCostAggregateService;
use App\Addons\SeoContentAi\Services\ContentProject\Operations\ContentProjectAuditSearchService;
use App\Addons\SeoContentAi\Services\ContentProject\Operations\ContentProjectCommandBusMonitorService;
use App\Addons\SeoContentAi\Services\ContentProject\Operations\ContentProjectDailyReportService;
use App\Addons\SeoContentAi\Services\ContentProject\Operations\ContentProjectErrorCenterService;
use App\Addons\SeoContentAi\Services\ContentProject\Operations\ContentProjectOpsDashboardService;
use App\Addons\SeoContentAi\Services\ContentProject\Operations\ContentProjectOpsHealthService;
use App\Addons\SeoContentAi\Services\ContentProject\Operations\ContentProjectOpsReplayService;
use App\Addons\SeoContentAi\Services\ContentProject\Operations\ContentProjectPublishAnalyticsService;
use App\Addons\SeoContentAi\Services\ContentProject\Operations\ContentProjectSiteHealthService;
use App\Addons\SeoContentAi\Services\ContentProject\Operations\ContentProjectWpAdapterMetricsService;
use App\Addons\SeoContentAi\Support\SeoAccessControl;
use App\Support\RuntimeLogger;
use Carbon\Carbon;
use Filament\Notifications\Notification;
use Illuminate\Support\Str;
use Throwable;

/**
 * Content Project Operation Center — Admin/Manager only.
 * Path: /seo/{connection_hash}/content-operations (SEO panel; needs omi_seo_ai).
 * Admin alias: /admin/content-operations redirects here.
 *
 * @see docs/CONTENT_PROJECT_OPERATIONS.md
 */
final class ContentProjectOperationsCenter extends SeoPanelPage
{
    protected static ?string $slug = 'content-operations';

    protected static ?string $navigationIcon = 'heroicon-o-signal';

    protected static ?string $navigationGroup = 'Content Projects';

    protected static ?int $navigationSort = 5;

    protected static string $view = 'seo-content-ai::filament.pages.content-project-operations-center';

    public string $activeTab = 'dashboard';

    /** @var array<string, mixed> */
    public array $dashboard = [];

    /** @var list<array<string, mixed>> */
    public array $operations = [];

    /** @var array<string, mixed> */
    public array $aiCost = [];

    /** @var array<string, mixed> */
    public array $publishAnalytics = [];

    /** @var array<string, mixed> */
    public array $wpMetrics = [];

    /** @var list<array<string, mixed>> */
    public array $errors = [];

    /** @var list<array<string, mixed>> */
    public array $healthChecks = [];

    /** @var list<array<string, mixed>> */
    public array $siteHealth = [];

    /** @var array<string, mixed> */
    public array $dailyReport = [];

    /** @var list<array<string, mixed>> */
    public array $timeline = [];

    /** @var list<array<string, mixed>> */
    public array $audits = [];

    /** @var list<array<string, mixed>> */
    public array $agentPlans = [];

    /** @var list<array<string, mixed>> */
    public array $agentApprovals = [];

    public string $filterCommand = '';

    public string $filterActor = '';

    public string $filterResultCode = '';

    public string $filterProjectRef = '';

    public string $filterTenant = '';

    public string $auditAction = '';

    public string $auditProjectRef = '';

    public string $timelineProjectId = '';

    public static function getNavigationLabel(): string
    {
        return __('seo-content-ai::filament.ops.nav');
    }

    public function getTitle(): string
    {
        return __('seo-content-ai::filament.ops.title');
    }

    public static function canAccess(): bool
    {
        return SeoAccessControl::canAccessContentOperations();
    }

    public function mount(): void
    {
        abort_unless(SeoAccessControl::canAccessContentOperations(), 403);
        $this->refreshAll();
    }

    public function switchTab(string $tab): void
    {
        $allowed = ['dashboard', 'commands', 'analytics', 'health', 'timeline', 'report', 'audit', 'plans', 'approvals'];
        if (! in_array($tab, $allowed, true)) {
            return;
        }

        $this->activeTab = $tab;
        $this->refreshTab($tab);
    }

    public function refreshAll(): void
    {
        $this->refreshTab('dashboard');
        $this->refreshTab($this->activeTab);
    }

    public function refreshTab(?string $tab = null): void
    {
        $tab ??= $this->activeTab;
        $siteIds = SeoAccessControl::accessibleSiteIds();
        $sites = $siteIds !== [] ? $siteIds : null;

        try {
            match ($tab) {
                'dashboard' => $this->dashboard = app(ContentProjectOpsDashboardService::class)->snapshot($sites),
                'commands' => $this->loadOperations(),
                'analytics' => $this->loadAnalytics($sites),
                'health' => $this->loadHealth($sites),
                'timeline' => $this->loadTimeline(),
                'report' => $this->dailyReport = app(ContentProjectDailyReportService::class)
                    ->buildForDate(Carbon::yesterday(), $sites),
                'audit' => $this->loadAudits(),
                'plans' => $this->loadAgentPlans(),
                'approvals' => $this->loadAgentApprovals(),
                default => null,
            };
        } catch (Throwable $e) {
            RuntimeLogger::report($e, ['endpoint' => 'content_project.ops.refresh', 'tab' => $tab]);
            Notification::make()
                ->title(__('seo-content-ai::filament.ops.refresh_failed'))
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function applyCommandFilters(): void
    {
        $this->loadOperations();
    }

    public function applyAuditFilters(): void
    {
        $this->loadAudits();
    }

    public function replayOperation(string $operationId): void
    {
        abort_unless(SeoAccessControl::canAccessContentOperations(), 403);

        $userId = auth()->id() !== null ? (int) auth()->id() : 0;
        if ($userId <= 0) {
            Notification::make()->title('Unauthorized')->danger()->send();

            return;
        }

        $result = app(ContentProjectOpsReplayService::class)->replay($operationId, $userId);
        app(ContentProjectActionResultNotifier::class)->send($result);
        $this->loadOperations();
    }

    public function loadTimelineForProject(): void
    {
        $this->loadTimeline();
    }

    public function approveAgentAction(string $approvalRef, string $fingerprint, int $siteId): void
    {
        abort_unless(SeoAccessControl::canAccessContentOperations(), 403);
        $result = app(ContentProjectAgentPlanApplicationService::class)->approve(
            $this->agentContextForSite($siteId),
            $approvalRef,
            $fingerprint,
        );
        $this->notifyAgentResult($result);
        $this->loadAgentApprovals();
    }

    public function rejectAgentAction(string $approvalRef, int $siteId): void
    {
        abort_unless(SeoAccessControl::canAccessContentOperations(), 403);
        $result = app(ContentProjectAgentPlanApplicationService::class)->rejectApproval(
            $this->agentContextForSite($siteId),
            $approvalRef,
        );
        $this->notifyAgentResult($result);
        $this->loadAgentApprovals();
    }

    public function pauseAgentPlan(string $planRef, int $siteId): void
    {
        abort_unless(SeoAccessControl::canAccessContentOperations(), 403);
        $result = app(ContentProjectAgentPlanApplicationService::class)->pausePlan(
            $this->agentContextForSite($siteId),
            $planRef,
        );
        $this->notifyAgentResult($result);
        $this->loadAgentPlans();
    }

    public function resumeAgentPlan(string $planRef, int $siteId): void
    {
        abort_unless(SeoAccessControl::canAccessContentOperations(), 403);
        $result = app(ContentProjectAgentPlanApplicationService::class)->resumePlan(
            $this->agentContextForSite($siteId),
            $planRef,
        );
        $this->notifyAgentResult($result);
        $this->loadAgentPlans();
    }

    public function cancelAgentPlan(string $planRef, int $siteId): void
    {
        abort_unless(SeoAccessControl::canAccessContentOperations(), 403);
        $result = app(ContentProjectAgentPlanApplicationService::class)->cancelPlan(
            $this->agentContextForSite($siteId),
            $planRef,
        );
        $this->notifyAgentResult($result);
        $this->loadAgentPlans();
    }

    public function retryAgentPlanStep(string $planRef, string $stepRef, int $siteId): void
    {
        abort_unless(SeoAccessControl::canAccessContentOperations(), 403);
        $result = app(ContentProjectAgentPlanApplicationService::class)->retryStep(
            $this->agentContextForSite($siteId),
            $planRef,
            $stepRef,
        );
        $this->notifyAgentResult($result);
        $this->loadAgentPlans();
    }

    private function loadAgentPlans(): void
    {
        $siteIds = SeoAccessControl::accessibleSiteIds();
        $query = ContentProjectAgentPlan::query()->orderByDesc('id')->limit(50);
        if ($siteIds !== []) {
            $query->whereIn('site_id', $siteIds);
        }

        $this->agentPlans = $query->get()->map(static fn (ContentProjectAgentPlan $plan): array => [
            'plan_ref' => (string) $plan->public_ref,
            'site_id' => (int) ($plan->site_id ?? 0),
            'objective' => (string) $plan->objective,
            'status' => (string) $plan->status,
            'total_steps' => (int) $plan->total_steps,
            'current_step_index' => (int) $plan->current_step_index,
            'created_at' => $plan->created_at?->toIso8601String(),
        ])->all();
    }

    private function loadAgentApprovals(): void
    {
        $siteIds = SeoAccessControl::accessibleSiteIds();
        $query = ContentProjectAgentApproval::query()
            ->where('status', 'pending')
            ->orderByDesc('id')
            ->limit(50);

        if ($siteIds !== []) {
            $query->where(function ($q) use ($siteIds): void {
                $q->whereIn('site_id', $siteIds)->orWhereNull('site_id');
            });
        }

        $this->agentApprovals = $query->get()->map(static fn (ContentProjectAgentApproval $row): array => [
            'approval_ref' => (string) $row->public_ref,
            'plan_ref' => (string) $row->plan_ref,
            'step_ref' => $row->step_ref,
            'site_id' => (int) ($row->site_id ?? 0),
            'action' => (string) $row->action,
            'summary' => (string) $row->summary,
            'risk_level' => (string) $row->risk_level,
            'state_fingerprint' => (string) $row->state_fingerprint,
            'expires_at' => $row->expires_at?->toIso8601String(),
            'destroy_workspace' => str_contains((string) $row->action, 'archive'),
        ])->all();
    }

    private function agentContextForSite(int $siteId): AgentExecutionContext
    {
        return AgentExecutionContext::fromArray([
            'actor_ref' => 'agent:user:'.(int) auth()->id(),
            'actor_type' => 'agent',
            'tenant_ref' => 'tenant:'.$siteId,
            'site_ref' => ContentProjectPublicRef::site($siteId),
            'request_ref' => (string) Str::uuid(),
            'resolved_site_id' => $siteId,
            'resolved_actor_user_id' => auth()->id() !== null ? (int) auth()->id() : null,
            'scopes' => ['content-project:admin'],
        ]);
    }

    /**
     * @param  array{success: bool, message: string}  $result
     */
    private function notifyAgentResult(array $result): void
    {
        $notification = Notification::make()->title($result['message'] ?? '');
        if ($result['success'] ?? false) {
            $notification->success();
        } else {
            $notification->danger();
        }
        $notification->send();
    }

    private function loadOperations(): void
    {
        $filters = array_filter([
            'command' => $this->filterCommand !== '' ? $this->filterCommand : null,
            'actor_type' => $this->filterActor !== '' ? $this->filterActor : null,
            'result_code' => $this->filterResultCode !== '' ? $this->filterResultCode : null,
            'project_ref' => $this->filterProjectRef !== '' ? $this->filterProjectRef : null,
            'tenant_ref' => $this->filterTenant !== '' ? $this->filterTenant : null,
            'limit' => 50,
        ], static fn ($v) => $v !== null);

        /** @var \Illuminate\Support\Collection<int, ContentProjectOperation> $rows */
        $rows = app(ContentProjectCommandBusMonitorService::class)->query($filters);

        $this->operations = $rows->map(static function (ContentProjectOperation $op): array {
            return [
                'operation_id' => (string) $op->operation_id,
                'request_id' => $op->request_id,
                'command' => (string) $op->command,
                'actor_type' => (string) $op->actor_type,
                'actor_id' => $op->actor_id,
                'started_at' => $op->started_at?->toIso8601String(),
                'finished_at' => $op->finished_at?->toIso8601String(),
                'duration_ms' => $op->duration_ms,
                'status' => (string) $op->status,
                'result_code' => $op->result_code,
                'success' => (bool) $op->success,
                'project_ref' => $op->project_ref,
                'item_ref' => $op->item_ref,
                'can_replay' => ! $op->success,
            ];
        })->all();
    }

    /** @param list<int>|null $sites */
    private function loadAnalytics(?array $sites): void
    {
        $this->aiCost = app(ContentProjectAiCostAggregateService::class)->aggregate(Carbon::today(), $sites);
        $this->publishAnalytics = app(ContentProjectPublishAnalyticsService::class)->snapshot($sites);
        $this->wpMetrics = app(ContentProjectWpAdapterMetricsService::class)->snapshot($sites);
        $this->errors = app(ContentProjectErrorCenterService::class)->topErrors($sites, 20);
    }

    /** @param list<int>|null $sites */
    private function loadHealth(?array $sites): void
    {
        $this->healthChecks = app(ContentProjectOpsHealthService::class)->checks();
        $siteIds = is_array($sites) ? $sites : SeoAccessControl::accessibleSiteIds();
        $this->siteHealth = app(ContentProjectSiteHealthService::class)->snapshot($siteIds);
    }

    private function loadTimeline(): void
    {
        $projectId = (int) $this->timelineProjectId;
        if ($projectId <= 0) {
            $this->timeline = [];

            return;
        }

        $project = SeoProject::query()->find($projectId);
        if (! $project instanceof SeoProject) {
            $this->timeline = [];

            return;
        }

        if (! SeoAccessControl::canAccessSite((int) ($project->site_id ?? 0))) {
            $this->timeline = [];

            return;
        }

        $this->timeline = app(ContentProjectTimelineService::class)->forProject($project);
    }

    private function loadAudits(): void
    {
        $this->audits = app(ContentProjectAuditSearchService::class)->search([
            'project_ref' => $this->auditProjectRef !== '' ? $this->auditProjectRef : null,
            'action' => $this->auditAction !== '' ? $this->auditAction : null,
            'limit' => 50,
        ]);
    }
}
