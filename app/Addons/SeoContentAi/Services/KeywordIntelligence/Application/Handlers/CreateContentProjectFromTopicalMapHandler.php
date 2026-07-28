<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Handlers;

use App\Addons\SeoContentAi\Services\ContentProject\Application\ActorContext;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ContentProjectActionResult;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ContentProjectCommandBus;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Commands\CreateContentProjectFromTopicalMapCommand;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\KeywordIntelligenceActionCodes;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Quotas\KeywordIntelligenceQuotaGuard;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Support\KeywordIntelligenceTenantGuard;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\KeywordTopicalMapMutationService;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\KeywordTopicalMapToContentProjectConverter;
use InvalidArgumentException;
use Throwable;

final class CreateContentProjectFromTopicalMapHandler extends AbstractKeywordIntelligenceHandler
{
    public function __construct(
        KeywordIntelligenceTenantGuard $tenantGuard,
        ContentProjectPreviewToken $previewToken,
        private readonly KeywordTopicalMapMutationService $mutations,
        private readonly KeywordTopicalMapToContentProjectConverter $converter,
        private readonly KeywordIntelligenceQuotaGuard $quota,
        private readonly ContentProjectCommandBus $bus,
    ) {
        parent::__construct($tenantGuard, $previewToken);
    }

    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof CreateContentProjectFromTopicalMapCommand) {
            throw new InvalidArgumentException('Expected CreateContentProjectFromTopicalMapCommand.');
        }

        return $this->wrap(function () use ($command, $actor): ContentProjectActionResult {
            $workspace = $this->resolveWorkspace($command->workspaceRef);
            $this->tenantGuard->assertCanAccessWorkspace($workspace, $actor);
            $this->assertNotArchived($workspace);

            // Agent must NOT convert draft map — converter asserts approved.
            $mapVersion = $this->mutations->resolveMapVersion($workspace, $command->mapVersionRef);

            if ($command->dryRun) {
                $preview = $this->converter->preview(
                    $workspace,
                    $mapVersion,
                    $command->policy,
                    $command->clusterRefs,
                );

                return ContentProjectActionResult::ok(
                    KeywordIntelligenceActionCodes::CONVERSION_PREVIEWED,
                    'Dry-run conversion preview ready.',
                    metadata: [
                        'workspace_ref' => $workspace->public_ref,
                        'preview' => $preview,
                        'dry_run' => true,
                    ],
                );
            }

            $preview = $this->converter->preview(
                $workspace,
                $mapVersion,
                $command->policy,
                $command->clusterRefs,
            );
            $eligible = (int) ($preview['eligible_clusters'] ?? 0);

            if ($eligible <= 0) {
                return ContentProjectActionResult::fail(
                    KeywordIntelligenceActionCodes::VALIDATION_FAILED,
                    'No eligible clusters for conversion.',
                    warnings: (array) ($preview['warnings'] ?? []),
                );
            }

            if (! $this->quota->canConvert($eligible)) {
                return ContentProjectActionResult::fail(
                    KeywordIntelligenceActionCodes::CONVERSION_TOO_LARGE,
                    'Cluster count exceeds convert quota.',
                );
            }

            $requiresConfirmation = $this->requiresConfirmation($actor, $command->confirmationToken)
                || $this->quota->requiresConfirmation($eligible);

            $fingerprint = $this->buildFingerprint('keyword_intelligence.create_content_project', (int) $workspace->id, [
                'map_version_ref' => $command->mapVersionRef,
                'policy' => $command->policy,
                'cluster_refs' => $command->clusterRefs,
                'project_attributes' => $command->projectAttributes,
            ]);

            $confirmationFailure = $this->assertConfirmationToken(
                $command->confirmationToken,
                $fingerprint,
                $requiresConfirmation,
            );
            if ($confirmationFailure !== null) {
                if ($command->confirmationToken === null || trim($command->confirmationToken) === '') {
                    $token = $this->previewToken->issue($fingerprint);

                    return ContentProjectActionResult::fail(
                        KeywordIntelligenceActionCodes::CONFIRMATION_REQUIRED,
                        'Confirmation required.',
                        metadata: [
                            'preview' => $preview,
                            'confirmation_token' => $token,
                        ],
                    );
                }

                return $confirmationFailure;
            }

            try {
                $result = $this->converter->convert(
                    $workspace,
                    $mapVersion,
                    $actor,
                    $this->bus,
                    $command->policy,
                    $command->clusterRefs,
                    $command->projectAttributes,
                    $command->idempotencyKey,
                );
            } catch (Throwable $e) {
                return ContentProjectActionResult::fail(
                    KeywordIntelligenceActionCodes::CONVERSION_FAILED,
                    'Conversion failed: '.$e->getMessage(),
                );
            }

            $this->consumeConfirmationToken($command->confirmationToken);

            return $result;
        });
    }
}
