<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Handlers;

use App\Addons\SeoContentAi\Services\ContentProject\Application\ActorContext;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ContentProjectActionResult;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Commands\MoveTopicCommand;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\KeywordIntelligenceActionCodes;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Support\KeywordIntelligenceTenantGuard;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\KeywordTopicalMapMutationService;
use InvalidArgumentException;

final class MoveTopicHandler extends AbstractKeywordIntelligenceHandler
{
    public function __construct(
        KeywordIntelligenceTenantGuard $tenantGuard,
        ContentProjectPreviewToken $previewToken,
        private readonly KeywordTopicalMapMutationService $mutations,
    ) {
        parent::__construct($tenantGuard, $previewToken);
    }

    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof MoveTopicCommand) {
            throw new InvalidArgumentException('Expected MoveTopicCommand.');
        }

        return $this->wrap(function () use ($command, $actor): ContentProjectActionResult {
            $workspace = $this->resolveWorkspace($command->workspaceRef);
            $this->tenantGuard->assertCanAccessWorkspace($workspace, $actor);
            $this->assertNotArchived($workspace);

            $preview = $this->mutations->previewMoveTopic($workspace, $command->topicRef, $command->newParentTopicRef);
            $fingerprint = $this->buildFingerprint('keyword_intelligence.move_topic', (int) $workspace->id, [
                'topic_ref' => $command->topicRef,
                'new_parent_topic_ref' => $command->newParentTopicRef,
            ]);

            $needsConfirm = (bool) ($preview['requires_confirmation'] ?? false)
                || $this->requiresConfirmation($actor, $command->confirmationToken);

            if ($command->dryRun || ($needsConfirm && ($command->confirmationToken === null || trim($command->confirmationToken) === ''))) {
                $token = $this->previewToken->issue($fingerprint);

                return ContentProjectActionResult::ok(
                    KeywordIntelligenceActionCodes::TOPICAL_MAP_CHANGE_SUGGESTED,
                    'Move topic preview ready.',
                    metadata: [
                        'preview' => [
                            'topic_ref' => $command->topicRef,
                            'new_parent_topic_ref' => $command->newParentTopicRef,
                            'descendant_count' => $preview['descendant_count'] ?? 0,
                            'requires_confirmation' => $preview['requires_confirmation'] ?? false,
                        ],
                        'confirmation_token' => $token,
                    ],
                );
            }

            if ($needsConfirm) {
                $denied = $this->assertConfirmationToken($command->confirmationToken, $fingerprint, true);
                if ($denied !== null) {
                    return $denied;
                }
            }

            $topic = $this->mutations->moveTopic($workspace, $command->topicRef, $command->newParentTopicRef);
            $this->consumeConfirmationToken($command->confirmationToken);

            return ContentProjectActionResult::ok(
                KeywordIntelligenceActionCodes::TOPIC_MOVED,
                'Topic moved.',
                metadata: [
                    'workspace_ref' => $workspace->public_ref,
                    'topic_ref' => $topic->public_ref,
                    'parent_topic_ref' => $command->newParentTopicRef,
                    'depth' => $topic->depth,
                ],
            );
        });
    }
}
