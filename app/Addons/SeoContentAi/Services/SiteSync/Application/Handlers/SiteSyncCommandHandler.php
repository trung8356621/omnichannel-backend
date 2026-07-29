<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\SiteSync\Application\Handlers;

use App\Addons\SeoContentAi\Jobs\SiteSync\ProcessSiteSyncInboundEventJob;
use App\Addons\SeoContentAi\Models\SiteSync\SeoSiteSyncInboundEvent;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ActorContext;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ContentProjectActionResult;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Contracts\ContentProjectCommandHandler;
use App\Addons\SeoContentAi\Services\SiteSync\Application\Commands\AcceptSiteProfileSuggestionCommand;
use App\Addons\SeoContentAi\Services\SiteSync\Application\Commands\BackfillSiteSyncV2Command;
use App\Addons\SeoContentAi\Services\SiteSync\Application\Commands\BootstrapSiteSyncCommand;
use App\Addons\SeoContentAi\Services\SiteSync\Application\Commands\CancelSiteSyncCommand;
use App\Addons\SeoContentAi\Services\SiteSync\Application\Commands\DiscoverSiteCommand;
use App\Addons\SeoContentAi\Services\SiteSync\Application\Commands\DiscoverSiteContactsCommand;
use App\Addons\SeoContentAi\Services\SiteSync\Application\Commands\ForceFullSiteSyncCommand;
use App\Addons\SeoContentAi\Services\SiteSync\Application\Commands\GenerateSiteSyncDiagnosticCommand;
use App\Addons\SeoContentAi\Services\SiteSync\Application\Commands\PreviewBootstrapSiteSyncCommand;
use App\Addons\SeoContentAi\Services\SiteSync\Application\Commands\QueueMissingSeoScoresCommand;
use App\Addons\SeoContentAi\Services\SiteSync\Application\Commands\ReconcileSiteSyncCommand;
use App\Addons\SeoContentAi\Services\SiteSync\Application\Commands\RefreshSiteSnapshotCommand;
use App\Addons\SeoContentAi\Services\SiteSync\Application\Commands\RejectSiteProfileSuggestionCommand;
use App\Addons\SeoContentAi\Services\SiteSync\Application\Commands\RequeueAllSeoScoresCommand;
use App\Addons\SeoContentAi\Services\SiteSync\Application\Commands\RequeueSiteSyncInboundEventCommand;
use App\Addons\SeoContentAi\Services\SiteSync\Application\Commands\ResumeSiteSyncCommand;
use App\Addons\SeoContentAi\Services\SiteSync\Application\Commands\RetryFailedSeoScoresCommand;
use App\Addons\SeoContentAi\Services\SiteSync\Application\Commands\RetrySiteSyncStepCommand;
use App\Addons\SeoContentAi\Services\SiteSync\Application\Commands\RunSiteSyncCommand;
use App\Addons\SeoContentAi\Services\SiteSync\Application\Commands\SyncSiteKeywordsCommand;
use App\Addons\SeoContentAi\Services\SiteSync\Application\Commands\SyncSiteLinksCommand;
use App\Addons\SeoContentAi\Services\SiteSync\Application\Commands\ValidateSiteSyncHandshakeCommand;
use App\Addons\SeoContentAi\Services\SiteSync\Backfill\SiteSyncV2BackfillService;
use App\Addons\SeoContentAi\Services\SiteSync\Bootstrap\SiteSyncBootstrapService;
use App\Addons\SeoContentAi\Services\SiteSync\Contracts\SiteSyncSchema;
use App\Addons\SeoContentAi\Services\SiteSync\Diagnostics\SiteSyncDiagnosticService;
use App\Addons\SeoContentAi\Services\SiteSync\Handshake\SiteSyncHandshakeService;
use App\Addons\SeoContentAi\Services\SiteSync\Orchestration\RunSiteSyncOrchestrator;
use App\Addons\SeoContentAi\Services\SiteSync\Profile\SiteProfileSuggestionService;
use App\Addons\SeoContentAi\Services\SiteSync\Reconciliation\SiteSyncReconciliationService;
use App\Models\Site;

final class SiteSyncCommandHandler implements ContentProjectCommandHandler
{
    public function __construct() {}

    private function orchestrator(): RunSiteSyncOrchestrator
    {
        return app(RunSiteSyncOrchestrator::class);
    }

    private function reconciliation(): SiteSyncReconciliationService
    {
        return app(SiteSyncReconciliationService::class);
    }

    private function bootstrap(): SiteSyncBootstrapService
    {
        return app(SiteSyncBootstrapService::class);
    }

    private function backfill(): SiteSyncV2BackfillService
    {
        return app(SiteSyncV2BackfillService::class);
    }

    private function handshake(): SiteSyncHandshakeService
    {
        return app(SiteSyncHandshakeService::class);
    }

    private function diagnostic(): SiteSyncDiagnosticService
    {
        return app(SiteSyncDiagnosticService::class);
    }

    private function profileSuggestions(): SiteProfileSuggestionService
    {
        return app(SiteProfileSuggestionService::class);
    }

    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        return match (true) {
            $command instanceof PreviewBootstrapSiteSyncCommand => $this->previewBootstrap($command),
            $command instanceof BootstrapSiteSyncCommand => $this->doBootstrap($command, $actor),
            $command instanceof BackfillSiteSyncV2Command => $this->doBackfill($command, $actor),
            $command instanceof ValidateSiteSyncHandshakeCommand => $this->doHandshake($command),
            $command instanceof GenerateSiteSyncDiagnosticCommand => $this->doDiagnostic($command),
            $command instanceof AcceptSiteProfileSuggestionCommand => $this->acceptSuggestion($command),
            $command instanceof RejectSiteProfileSuggestionCommand => $this->rejectSuggestion($command),
            $command instanceof ForceFullSiteSyncCommand => $this->forceFull($command, $actor),
            $command instanceof QueueMissingSeoScoresCommand => $this->queueMissingScores($command),
            $command instanceof RetryFailedSeoScoresCommand => $this->retryFailedScores($command),
            $command instanceof RequeueAllSeoScoresCommand => $this->requeueAllScores($command),
            $command instanceof RunSiteSyncCommand => $this->runSmart($command, $actor),
            $command instanceof DiscoverSiteCommand => $this->run($command->siteId, [
                'steps' => ['detect_capability', 'sync_site_profile', 'finalize'],
                'trigger_source' => 'agent',
                'triggered_by' => $actor->actorId,
            ]),
            $command instanceof SyncSiteKeywordsCommand => $this->run($command->siteId, [
                'steps' => [
                    'detect_capability',
                    'request_snapshot_delta',
                    'sync_provider_keywords',
                    'missing_capability_fallback',
                    'finalize',
                ],
                'trigger_source' => 'agent',
                'triggered_by' => $actor->actorId,
            ]),
            $command instanceof SyncSiteLinksCommand => $this->run($command->siteId, [
                'steps' => [
                    'detect_capability',
                    'request_snapshot_delta',
                    'sync_url_catalog',
                    'validate_changed_links',
                    'finalize',
                ],
                'trigger_source' => 'agent',
                'triggered_by' => $actor->actorId,
            ]),
            $command instanceof DiscoverSiteContactsCommand => $this->run($command->siteId, [
                'steps' => ['detect_capability', 'sync_site_profile', 'finalize'],
                'trigger_source' => 'agent',
                'triggered_by' => $actor->actorId,
            ]),
            $command instanceof RefreshSiteSnapshotCommand => $this->run($command->siteId, [
                'mode' => SiteSyncSchema::MODE_SNAPSHOT,
                'force_snapshot' => true,
                'trigger_source' => 'agent',
                'triggered_by' => $actor->actorId,
            ]),
            $command instanceof ResumeSiteSyncCommand => $this->mapOrchestrator(
                $this->orchestrator()->resume($command->runId)
            ),
            $command instanceof RetrySiteSyncStepCommand => $this->mapOrchestrator(
                $this->orchestrator()->retryStep($command->runId, $command->stepKey)
            ),
            $command instanceof CancelSiteSyncCommand => $this->mapOrchestrator(
                $this->orchestrator()->cancel($command->runId)
            ),
            $command instanceof ReconcileSiteSyncCommand => $this->reconcile($command),
            $command instanceof RequeueSiteSyncInboundEventCommand => $this->requeue($command),
            default => ContentProjectActionResult::fail('site.unsupported', 'Unsupported site sync command.'),
        };
    }

    private function previewBootstrap(PreviewBootstrapSiteSyncCommand $command): ContentProjectActionResult
    {
        $site = Site::query()->find($command->siteId);
        if ($site === null) {
            return ContentProjectActionResult::fail('site.not_found', 'Site not found.');
        }
        $preview = $this->bootstrap()->preview($site);

        return ContentProjectActionResult::ok('site.preview_bootstrap_ok', 'Bootstrap preview', metadata: $preview);
    }

    private function doBootstrap(BootstrapSiteSyncCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        $site = Site::query()->find($command->siteId);
        if ($site === null) {
            return ContentProjectActionResult::fail('site.not_found', 'Site not found.');
        }

        return $this->mapOrchestrator($this->bootstrap()->start($site, [
            'force' => $command->force,
            'trigger_source' => 'command_bus',
            'triggered_by' => $actor->actorId,
        ]));
    }

    private function doBackfill(BackfillSiteSyncV2Command $command, ActorContext $actor): ContentProjectActionResult
    {
        if ($command->force && $actor->actorType !== 'user' && $actor->actorType !== 'system') {
            return ContentProjectActionResult::fail('site.backfill_forbidden', 'Force backfill requires admin user.');
        }
        $site = Site::query()->find($command->siteId);
        if ($site === null) {
            return ContentProjectActionResult::fail('site.not_found', 'Site not found.');
        }
        $report = $this->backfill()->run(
            $site,
            $command->only,
            $command->dryRun,
            $command->batch,
            $command->resumeId,
        );

        return ContentProjectActionResult::ok(
            'site.backfill_ok',
            $command->dryRun ? 'Backfill dry-run report' : 'Backfill applied',
            metadata: $report,
        );
    }

    private function doHandshake(ValidateSiteSyncHandshakeCommand $command): ContentProjectActionResult
    {
        $site = Site::query()->find($command->siteId);
        if ($site === null) {
            return ContentProjectActionResult::fail('site.not_found', 'Site not found.');
        }
        $result = $this->handshake()->validate($site);

        return ContentProjectActionResult::ok('site.handshake_ok', (string) $result['message'], metadata: $result);
    }

    private function doDiagnostic(GenerateSiteSyncDiagnosticCommand $command): ContentProjectActionResult
    {
        $site = Site::query()->find($command->siteId);
        if ($site === null) {
            return ContentProjectActionResult::fail('site.not_found', 'Site not found.');
        }

        return ContentProjectActionResult::ok(
            'site.diagnostic_ok',
            'Diagnostic report (readonly)',
            metadata: $this->diagnostic()->generate($site),
        );
    }

    private function acceptSuggestion(AcceptSiteProfileSuggestionCommand $command): ContentProjectActionResult
    {
        $site = Site::query()->find($command->siteId);
        if ($site === null) {
            return ContentProjectActionResult::fail('site.not_found', 'Site not found.');
        }
        $result = $this->profileSuggestions()->accept($site, $command->suggestionHash);
        if (! ($result['success'] ?? false)) {
            return ContentProjectActionResult::fail('site.suggestion_missing', (string) ($result['message'] ?? ''));
        }

        return ContentProjectActionResult::ok('site.suggestion_accepted', (string) $result['message'], metadata: $result);
    }

    private function rejectSuggestion(RejectSiteProfileSuggestionCommand $command): ContentProjectActionResult
    {
        $site = Site::query()->find($command->siteId);
        if ($site === null) {
            return ContentProjectActionResult::fail('site.not_found', 'Site not found.');
        }
        $result = $this->profileSuggestions()->reject($site, $command->suggestionHash);

        return ContentProjectActionResult::ok('site.suggestion_rejected', (string) $result['message'], metadata: $result);
    }

    private function forceFull(ForceFullSiteSyncCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        return $this->run($command->siteId, [
            'mode' => SiteSyncSchema::MODE_FORCE_FULL,
            'force_full' => true,
            'supersede_active' => $command->supersedeActive,
            'trigger_source' => 'ui',
            'triggered_by' => $actor->actorId,
            'meta' => array_filter([
                'operation_id' => $command->operationId,
                'idempotency_key' => $command->idempotencyKey,
            ], static fn (mixed $v): bool => $v !== null && $v !== ''),
        ]);
    }

    private function queueMissingScores(QueueMissingSeoScoresCommand $command): ContentProjectActionResult
    {
        $site = Site::query()->find($command->siteId);
        if ($site === null) {
            return ContentProjectActionResult::fail('site.not_found', 'Site not found.');
        }
        app(\App\Addons\SeoContentAi\Services\SeoDatabaseConnectionService::class)
            ->bootstrapSeoDatabaseConnection($command->siteId);
        $result = app(\App\Addons\SeoContentAi\Services\SeoArticleScoringQueueService::class)
            ->queueMissingOrStaleForSite($command->siteId, [
                'run_id' => $command->runId,
                'operation_id' => $command->operationId,
            ]);

        return ContentProjectActionResult::ok(
            'site.score_missing_ok',
            sprintf('Đã xếp hàng chấm SEO: %d bài.', (int) ($result['queued'] ?? 0)),
            metadata: $result,
        );
    }

    private function retryFailedScores(RetryFailedSeoScoresCommand $command): ContentProjectActionResult
    {
        $site = Site::query()->find($command->siteId);
        if ($site === null) {
            return ContentProjectActionResult::fail('site.not_found', 'Site not found.');
        }
        app(\App\Addons\SeoContentAi\Services\SeoDatabaseConnectionService::class)
            ->bootstrapSeoDatabaseConnection($command->siteId);
        $result = app(\App\Addons\SeoContentAi\Services\SeoArticleScoringQueueService::class)
            ->queueFailedForSite($command->siteId);

        return ContentProjectActionResult::ok(
            'site.score_retry_ok',
            sprintf('Đã xếp hàng retry: %d bài.', (int) ($result['queued'] ?? 0)),
            metadata: $result,
        );
    }

    private function requeueAllScores(RequeueAllSeoScoresCommand $command): ContentProjectActionResult
    {
        if (! $command->confirmed) {
            return ContentProjectActionResult::fail(
                'site.score_requeue_needs_confirm',
                'Cần xác nhận trước khi chấm lại toàn bộ bài viết.',
            );
        }
        $site = Site::query()->find($command->siteId);
        if ($site === null) {
            return ContentProjectActionResult::fail('site.not_found', 'Site not found.');
        }
        app(\App\Addons\SeoContentAi\Services\SeoDatabaseConnectionService::class)
            ->bootstrapSeoDatabaseConnection($command->siteId);
        $preview = app(\App\Addons\SeoContentAi\Services\SeoArticleScoringQueueService::class)
            ->domainProgress($command->siteId);
        $result = app(\App\Addons\SeoContentAi\Services\SeoArticleScoringQueueService::class)
            ->queueAllForSite($command->siteId);

        return ContentProjectActionResult::ok(
            'site.score_requeue_all_ok',
            sprintf(
                'Đã xếp hàng chấm lại toàn bộ: %d/%d bài (Workspace score only).',
                (int) ($result['queued'] ?? 0),
                (int) ($preview['total'] ?? 0),
            ),
            metadata: array_merge($result, ['preview_total' => (int) ($preview['total'] ?? 0)]),
        );
    }

    private function runSmart(RunSiteSyncCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        $site = Site::query()->find($command->siteId);
        if ($site === null) {
            return ContentProjectActionResult::fail('site.not_found', 'Site not found.');
        }

        // Priority: force_full → bootstrap (never synced) → incremental.
        // Agent default mode is delta — never auto-promote to force_full.
        if ($command->mode === SiteSyncSchema::MODE_FORCE_FULL) {
            return $this->run($command->siteId, [
                'mode' => SiteSyncSchema::MODE_FORCE_FULL,
                'force_full' => true,
                'supersede_active' => true,
                'force_snapshot' => $command->forceSnapshot,
                'steps' => $command->steps,
                'trigger_source' => 'agent_explicit_force_full',
                'triggered_by' => $actor->actorId,
            ]);
        }

        if ($this->bootstrap()->needsBootstrap($site)) {
            return $this->mapOrchestrator($this->bootstrap()->start($site, [
                'trigger_source' => 'agent_auto_bootstrap',
                'triggered_by' => $actor->actorId,
                'force' => $command->forceSnapshot,
            ]));
        }

        return $this->run($command->siteId, [
            'mode' => $command->mode,
            'force_snapshot' => $command->forceSnapshot,
            'steps' => $command->steps,
            'trigger_source' => 'agent',
            'triggered_by' => $actor->actorId,
        ]);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function run(int $siteId, array $options): ContentProjectActionResult
    {
        $site = Site::query()->find($siteId);
        if ($site === null) {
            return ContentProjectActionResult::fail('site.not_found', 'Site not found.');
        }

        return $this->mapOrchestrator($this->orchestrator()->start($site, $options));
    }

    /**
     * @param  array{success: bool, message: string, run_id?: int, public_ref?: string}  $result
     */
    private function mapOrchestrator(array $result): ContentProjectActionResult
    {
        if (! ($result['success'] ?? false)) {
            return ContentProjectActionResult::fail(
                'site.sync_failed',
                (string) ($result['message'] ?? 'Site sync failed'),
                metadata: $result,
            );
        }

        return ContentProjectActionResult::ok(
            'site.sync_ok',
            (string) ($result['message'] ?? 'ok'),
            metadata: $result,
        );
    }

    private function reconcile(ReconcileSiteSyncCommand $command): ContentProjectActionResult
    {
        $site = Site::query()->find($command->siteId);
        if ($site === null) {
            return ContentProjectActionResult::fail('site.not_found', 'Site not found.');
        }
        $result = $this->reconciliation()->reconcile($site, $command->mode);
        if (! ($result['success'] ?? false)) {
            return ContentProjectActionResult::fail('site.reconcile_failed', (string) ($result['message'] ?? ''), metadata: $result);
        }

        return ContentProjectActionResult::ok('site.reconcile_ok', (string) ($result['message'] ?? ''), metadata: $result);
    }

    private function requeue(RequeueSiteSyncInboundEventCommand $command): ContentProjectActionResult
    {
        $event = SeoSiteSyncInboundEvent::query()
            ->where('site_id', $command->siteId)
            ->whereKey($command->eventId)
            ->first();
        if ($event === null) {
            return ContentProjectActionResult::fail('site.event_not_found', 'Inbound event not found.');
        }
        $event->forceFill([
            'status' => SeoSiteSyncInboundEvent::STATUS_QUEUED,
            'retry_after' => null,
            'last_error_code' => null,
            'last_error_message' => null,
        ])->save();
        ProcessSiteSyncInboundEventJob::dispatch((int) $event->id);

        return ContentProjectActionResult::ok('site.requeue_ok', 'Inbound event requeued.');
    }
}
