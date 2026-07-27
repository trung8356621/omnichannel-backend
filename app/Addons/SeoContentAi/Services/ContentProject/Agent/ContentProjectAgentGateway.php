<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\ContentProject\Agent;

use App\Addons\SeoContentAi\Services\ContentProject\Application\Capabilities\CanonicalCapabilityRegistry;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ContentProjectActionCodes;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ContentProjectActionResult;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ContentProjectCommandBus;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ContentProjectPublicRef;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Agent\KeywordIntelligenceReadService;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Central Agent Gateway — orchestration only, no business logic.
 */
final class ContentProjectAgentGateway
{
    /** @var list<string> */
    public const READ_CAPABILITIES = [
        'content_project.list_projects',
        'content_project.get_project',
        'content_project.list_items',
        'content_project.get_item',
        'content_project.get_status',
        'content_project.get_publishing_queue',
        'content_project.get_timeline',
        'content_project.get_daily_report',
        'content_project.get_site_health',
        'content_project.get_operation',

        // Keyword Intelligence — additive read surface.
        'keyword_intelligence.list_workspaces',
        'keyword_intelligence.get_workspace',
        'keyword_intelligence.list_keywords',
        'keyword_intelligence.list_clusters',
        'keyword_intelligence.get_topical_map',
        'keyword_intelligence.get_cannibalization',
        'keyword_intelligence.get_analysis_operation',
    ];

    public function __construct(
        private readonly CanonicalCapabilityRegistry $registry,
        private readonly ContentProjectAgentReadService $reads,
        private readonly ContentProjectAgentPolicy $policy,
        private readonly ContentProjectAgentSchemaValidator $schemaValidator,
        private readonly ContentProjectAgentCommandFactory $commandFactory,
        private readonly ContentProjectAgentRateLimiter $rateLimiter,
        private readonly ContentProjectAgentSessionService $sessions,
        private readonly ContentProjectPreviewToken $previewToken,
        private readonly ContentProjectCommandBus $commandBus,
        private readonly KeywordIntelligenceReadService $keywordReads,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function execute(AgentExecutionContext $context, string $capability, array $input = []): AgentCapabilityResult
    {
        try {
            return $this->executeInternal($context, $capability, $input);
        } catch (InvalidArgumentException $e) {
            return AgentCapabilityResult::fail(
                AgentErrorCodes::INVALID_INPUT,
                $e->getMessage(),
                meta: ['request_ref' => $context->requestRef],
            );
        } catch (RuntimeException $e) {
            return AgentCapabilityResult::fail(
                AgentErrorCodes::RESOURCE_NOT_FOUND,
                $e->getMessage(),
                meta: ['request_ref' => $context->requestRef],
            );
        } catch (Throwable) {
            return AgentCapabilityResult::fail(
                AgentErrorCodes::INTERNAL_ERROR,
                'Agent request failed.',
                meta: ['request_ref' => $context->requestRef],
            );
        }
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function executeInternal(AgentExecutionContext $context, string $capability, array $input): AgentCapabilityResult
    {
        if ($capability === 'content_project.rerun_items') {
            $capability = 'content_project.rerun';
        }

        if ($context->actorType !== 'agent') {
            return AgentCapabilityResult::fail(
                AgentErrorCodes::AUTHENTICATION_FAILED,
                'Invalid actor type.',
            );
        }

        if ($context->tenantRef === '' || $context->siteRef === '') {
            return AgentCapabilityResult::fail(
                AgentErrorCodes::CONTEXT_MISSING,
                'tenant_ref and site_ref are required.',
            );
        }

        $siteId = ContentProjectPublicRef::resolveSiteIdStrict($context->siteRef);
        if (! $this->tenantMatchesSite($context->tenantRef, $context->siteRef, $siteId)) {
            return AgentCapabilityResult::fail(
                AgentErrorCodes::TENANT_ACCESS_DENIED,
                'tenant_ref does not match site_ref.',
            );
        }

        $context = $context->withResolved($siteId, $context->resolvedActorUserId);

        if ($rate = $this->rateLimiter->checkRequest($context)) {
            return $this->withRequestMeta($rate, $context);
        }

        if ($context->sessionRef !== null && $context->sessionRef !== '') {
            $session = $this->sessions->findByPublicRef($context->sessionRef);
            if ($session === null) {
                return AgentCapabilityResult::fail(
                    AgentErrorCodes::SESSION_EXPIRED,
                    'Session not found or expired.',
                );
            }
            $this->sessions->touch($session);
        }

        if (in_array($capability, self::READ_CAPABILITIES, true)) {
            if ($scopeFail = $this->policy->assertScopes($context->scopes, $capability)) {
                return $this->withRequestMeta($scopeFail, $context);
            }

            return $this->withRequestMeta(
                $this->executeRead($context, $capability, $input),
                $context,
            );
        }

        if ($capability === 'content_project.create' && ($rate = $this->rateLimiter->checkCreate($context))) {
            return $this->withRequestMeta($rate, $context);
        }

        if ($capability === 'content_project.archive' && ($rate = $this->rateLimiter->checkArchive($context))) {
            return $this->withRequestMeta($rate, $context);
        }

        $cap = $this->registry->get($capability);
        if ($cap === null) {
            return AgentCapabilityResult::fail(
                AgentErrorCodes::CAPABILITY_NOT_FOUND,
                'Capability not found.',
            );
        }

        $schemaErrors = $this->schemaValidator->validate($capability, $input);
        if ($schemaErrors !== []) {
            return AgentCapabilityResult::fail(
                AgentErrorCodes::INVALID_INPUT,
                'Input validation failed.',
                data: ['errors' => $schemaErrors],
            );
        }

        if ($numericFail = $this->policy->assertNoNumericIds($input)) {
            return $this->withRequestMeta($numericFail, $context);
        }

        if ($scopeFail = $this->policy->assertScopes($context->scopes, $capability)) {
            return $this->withRequestMeta($scopeFail, $context);
        }

        if ($safeFail = $this->policy->assertSafeWrite($capability, $input, $siteId)) {
            return $this->withRequestMeta($safeFail, $context);
        }

        $requiresConfirmation = (bool) ($cap['confirmation_requirement'] ?? false);
        $dryRunSupport = (bool) ($cap['dry_run_support'] ?? false);
        $confirmationToken = $context->confirmationToken ?? (isset($input['confirmation_token']) ? (string) $input['confirmation_token'] : null);

        if ($requiresConfirmation && ! $context->dryRun && ($confirmationToken === null || $confirmationToken === '')) {
            if ($dryRunSupport) {
                return $this->issueConfirmationPreview($context, $capability, $input, $cap);
            }

            return AgentCapabilityResult::fail(
                AgentErrorCodes::CONFIRMATION_REQUIRED,
                'Confirmation token required.',
                meta: [
                    'request_ref' => $context->requestRef,
                    'requires_confirmation' => true,
                ],
            );
        }

        if ($confirmationToken !== null && $confirmationToken !== '') {
            $validation = $this->validateConfirmationToken($context, $capability, $input, $confirmationToken);
            if ($validation !== null) {
                return $this->withRequestMeta($validation, $context);
            }
            $this->previewToken->consume($confirmationToken);
        }

        $command = $this->commandFactory->build($capability, $input, $siteId);
        $result = $this->commandBus->dispatch($command, $context->toActorContext());

        $agentResult = $this->mapActionResult($result, $context, $capability);

        if ($context->sessionRef !== null && $context->sessionRef !== '') {
            $session = $this->sessions->findByPublicRef($context->sessionRef);
            if ($session !== null) {
                $patch = [
                    'last_operation_ref' => $agentResult->meta['operation_ref'] ?? null,
                ];
                if ($result->projectId !== null) {
                    $patch['last_project_ref'] = ContentProjectPublicRef::project($result->projectId);
                }
                $this->sessions->updateMetadata($session, $patch);

                if ($capability === 'content_project.archive' && $result->success) {
                    $this->sessions->clearWorkspaceContext($session);
                }
            }
        }

        return $agentResult;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function executeRead(AgentExecutionContext $context, string $capability, array $input): AgentCapabilityResult
    {
        if ($capability === 'content_project.get_operation') {
            $operationRef = trim((string) ($input['operation_ref'] ?? ''));
            if ($operationRef !== '' && ($poll = $this->rateLimiter->checkPoll($context, $operationRef))) {
                return $this->withRequestMeta($poll, $context);
            }
        }

        $data = match ($capability) {
            'content_project.list_projects' => $this->reads->listProjects($context, $input),
            'content_project.get_project' => ['project' => $this->reads->getProject($context, $input)],
            'content_project.list_items' => $this->reads->listItems($context, $input),
            'content_project.get_item' => ['item' => $this->reads->getItem($context, $input)],
            'content_project.get_status' => $this->reads->getStatus($context, $input),
            'content_project.get_publishing_queue' => $this->reads->getPublishingQueue($context, $input),
            'content_project.get_timeline' => $this->reads->getTimeline($context, $input),
            'content_project.get_daily_report' => $this->reads->getDailyReport($context, $input),
            'content_project.get_site_health' => $this->reads->getSiteHealth($context, $input),
            'content_project.get_operation' => ['operation' => $this->reads->getOperation($context, $input)],

            // Keyword Intelligence — additive read surface.
            'keyword_intelligence.list_workspaces' => $this->keywordReads->listWorkspaces($context, $input),
            'keyword_intelligence.get_workspace' => $this->keywordReads->getWorkspace($context, $input),
            'keyword_intelligence.list_keywords' => $this->keywordReads->listKeywords($context, $input),
            'keyword_intelligence.list_clusters' => $this->keywordReads->listClusters($context, $input),
            'keyword_intelligence.get_topical_map' => $this->keywordReads->getTopicalMap($context, $input),
            'keyword_intelligence.get_cannibalization' => $this->keywordReads->getCannibalization($context, $input),
            'keyword_intelligence.get_analysis_operation' => $this->keywordReads->getAnalysisOperation($context, $input),
            default => throw new InvalidArgumentException('Unsupported read capability.'),
        };

        return AgentCapabilityResult::ok('agent.read_ok', 'Read successful.', $data);
    }

    /**
     * @param  array<string, mixed>  $cap
     * @param  array<string, mixed>  $input
     */
    private function issueConfirmationPreview(
        AgentExecutionContext $context,
        string $capability,
        array $input,
        array $cap,
    ): AgentCapabilityResult {
        $fingerprint = [
            'tenant_site_id' => $context->resolvedSiteId,
            'actor_type' => 'agent',
            'actor_id' => $context->resolvedActorUserId,
            'action' => $capability,
            'project_ref' => (string) ($input['project_ref'] ?? ''),
            'item_refs' => is_array($input['item_refs'] ?? null) ? $input['item_refs'] : [],
            'input' => $input,
        ];

        $token = $this->previewToken->issue($fingerprint);
        $preview = [
            'capability' => $capability,
            'input' => $input,
            'confirmation_token' => $token,
        ];

        if ($capability === 'content_project.archive') {
            $preview['workspace_destroyed'] = true;
            $preview['destructive_effects'] = [
                'AI Workspace',
                'Prompt History',
                'Execution records',
                'Local media',
                'SaaS revisions',
            ];
            $preview['warning'] = 'Archive will destroy the entire AI Workspace, Prompt History, Execution records, local media, and SaaS revisions. Business project metadata remains.';
        }

        return AgentCapabilityResult::fail(
            AgentErrorCodes::CONFIRMATION_REQUIRED,
            'Confirmation required. Review preview and resubmit with confirmation_token.',
            data: ['preview' => $preview],
            meta: [
                'request_ref' => $context->requestRef,
                'requires_confirmation' => true,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function validateConfirmationToken(
        AgentExecutionContext $context,
        string $capability,
        array $input,
        string $token,
    ): ?AgentCapabilityResult {
        $fingerprint = [
            'tenant_site_id' => $context->resolvedSiteId,
            'actor_type' => 'agent',
            'actor_id' => $context->resolvedActorUserId,
            'action' => $capability,
            'project_ref' => (string) ($input['project_ref'] ?? ''),
            'item_refs' => is_array($input['item_refs'] ?? null) ? $input['item_refs'] : [],
            'input' => $input,
        ];

        $status = $this->previewToken->validate($token, $fingerprint);

        return match ($status) {
            'ok' => null,
            'expired' => AgentCapabilityResult::fail(AgentErrorCodes::CONFIRMATION_EXPIRED, 'Confirmation token expired.'),
            'stale' => AgentCapabilityResult::fail(AgentErrorCodes::CONFIRMATION_STALE, 'Confirmation token stale.'),
            default => AgentCapabilityResult::fail(AgentErrorCodes::CONFIRMATION_INVALID, 'Confirmation token invalid.'),
        };
    }

    private function mapActionResult(
        ContentProjectActionResult $result,
        AgentExecutionContext $context,
        string $capability,
    ): AgentCapabilityResult {
        $api = $result->toApiArray($context->requestRef);
        $meta = is_array($api['meta'] ?? null) ? $api['meta'] : [];
        $meta['request_ref'] = $context->requestRef;
        $meta['requires_confirmation'] = false;

        if (isset($result->metadata['operation_id'])) {
            $meta['operation_ref'] = (string) $result->metadata['operation_id'];
        } elseif (isset($result->metadata['operation_ref'])) {
            $meta['operation_ref'] = (string) $result->metadata['operation_ref'];
        } else {
            $meta['operation_ref'] = 'op_'.$context->requestRef;
        }

        if ($result->code === ContentProjectActionCodes::IDEMPOTENT_REPLAY) {
            $meta['idempotent_replay'] = true;
        }

        $nextActions = $this->suggestNextActions($capability, $result);

        if ($result->success) {
            return AgentCapabilityResult::ok(
                $result->code,
                $result->message,
                is_array($api['data'] ?? null) ? $api['data'] : [],
                $result->warnings,
                $nextActions,
                $meta,
            );
        }

        return AgentCapabilityResult::fail(
            $result->code,
            $result->message,
            is_array($api['data'] ?? null) ? $api['data'] : [],
            $result->warnings,
            $nextActions,
            $meta,
        );
    }

    /**
     * @return list<array{capability: string, reason: string}>
     */
    private function suggestNextActions(string $capability, ContentProjectActionResult $result): array
    {
        if (! $result->success) {
            return match ($result->code) {
                AgentErrorCodes::APPROVAL_REVIEW_REQUIRED,
                ContentProjectActionCodes::LIFECYCLE_INVALID => [
                    ['capability' => 'content_project.get_status', 'reason' => 'Inspect lifecycle blockers.'],
                ],
                ContentProjectActionCodes::CONFIRMATION_REQUIRED => [],
                default => [],
            };
        }

        return match ($capability) {
            'content_project.generate' => [
                ['capability' => 'content_project.get_operation', 'reason' => 'Poll generation progress.'],
                ['capability' => 'content_project.get_status', 'reason' => 'Check project phase after generation.'],
            ],
            'content_project.publish_now' => [
                ['capability' => 'content_project.get_publishing_queue', 'reason' => 'Monitor publish queue.'],
            ],
            'content_project.archive' => [],
            default => [],
        };
    }

    private function tenantMatchesSite(string $tenantRef, string $siteRef, int $siteId): bool
    {
        if ($tenantRef === $siteRef) {
            return true;
        }

        if (str_starts_with($tenantRef, 'tenant:')) {
            $suffix = substr($tenantRef, 7);
            if ($suffix === $siteRef) {
                return true;
            }

            try {
                return ContentProjectPublicRef::resolveSiteIdStrict($suffix) === $siteId;
            } catch (InvalidArgumentException) {
                return false;
            }
        }

        return false;
    }

    private function withRequestMeta(AgentCapabilityResult $result, AgentExecutionContext $context): AgentCapabilityResult
    {
        $meta = $result->meta;
        $meta['request_ref'] = $context->requestRef;

        return new AgentCapabilityResult(
            success: $result->success,
            code: $result->code,
            message: $result->message,
            data: $result->data,
            warnings: $result->warnings,
            nextActions: $result->nextActions,
            meta: $meta,
        );
    }
}
