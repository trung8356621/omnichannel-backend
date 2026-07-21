<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Automation\Actions\Article;

use App\Addons\SeoContentAi\Automation\Contracts\BusinessAction;
use App\Addons\SeoContentAi\Automation\Data\ActionContext;
use App\Addons\SeoContentAi\Automation\Data\ActionDefinition;
use App\Addons\SeoContentAi\Automation\Data\ActionResult;
use App\Addons\SeoContentAi\Automation\Enums\ActionRiskLevel;
use App\Addons\SeoContentAi\Automation\Enums\ActionSelectability;
use App\Addons\SeoContentAi\Automation\Enums\ActionSideEffect;
use App\Addons\SeoContentAi\Automation\Support\ActionSupport;
use App\Addons\SeoContentAi\Services\SeoProjectApprovalService;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Approve linked content project for article (content manager).
 */
final class ApproveArticleAction implements BusinessAction
{
    public function __construct(
        private readonly SeoProjectApprovalService $approvalService,
    ) {}

    public static function definition(): ActionDefinition
    {
        return new ActionDefinition(
            key: 'article.approve',
            name: 'Approve article (linked project)',
            description: 'Content manager marks staff editing complete → approves linked SeoProject.',
            module: 'article',
            sideEffect: ActionSideEffect::InternalWrite,
            riskLevel: ActionRiskLevel::Low,
            selectability: ActionSelectability::Selectable,
            inputSchema: [
                'article_id' => ['type' => 'integer', 'required' => true],
                'actor_user_id' => ['type' => 'integer', 'required' => false],
            ],
            outputSchema: [
                'article_id' => ['type' => 'integer'],
                'project_id' => ['type' => 'integer'],
                'project_name' => ['type' => 'string'],
                'already_approved' => ['type' => 'boolean'],
            ],
            idempotent: true,
            lockScope: 'article',
            emittedEvents: ['article.approved'],
        );
    }

    public function execute(ActionContext $context, array $input): ActionResult
    {
        if ($denied = ActionSupport::assertMutable($context)) {
            return $denied;
        }

        $articleId = (int) ($input['article_id'] ?? 0);
        $article = ActionSupport::findArticle($articleId);
        if ($article === null) {
            return ActionResult::failure('article_not_found', "Article [{$articleId}] not found.");
        }

        $actorId = (int) ($input['actor_user_id'] ?? $context->actorId ?? 0);
        $user = $actorId > 0 ? User::query()->find($actorId) : null;
        if (! $user instanceof User) {
            return ActionResult::failure('actor_required', 'article.approve requires an authenticated actor.');
        }

        $already = $this->approvalService->contentManagerHasSubmitted($article, $user);

        try {
            $project = $this->approvalService->approveLinkedProject($article, $user);
        } catch (ValidationException $exception) {
            return ActionResult::failure(
                'approval_rejected',
                (string) (collect($exception->errors())->flatten()->first() ?? $exception->getMessage()),
            );
        }

        return ActionResult::success(
            output: [
                'article_id' => $articleId,
                'project_id' => (int) $project->id,
                'project_name' => (string) ($project->name ?? ''),
                'already_approved' => $already,
            ],
            events: $already ? [] : [
                ActionSupport::articleEvent('article.approved', $context, $articleId, [
                    'project_id' => (int) $project->id,
                ]),
            ],
            changed: $already ? [] : ['project_status'],
        );
    }
}
