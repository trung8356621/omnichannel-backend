<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Automation\BusinessHook\Actions;

use App\Addons\SeoContentAi\Automation\BusinessHook\Contracts\AutomationActionHandler;
use App\Addons\SeoContentAi\Automation\BusinessHook\Data\AutomationActionContext;
use App\Addons\SeoContentAi\Automation\BusinessHook\Data\AutomationActionResult;
use App\Addons\SeoContentAi\Automation\BusinessHook\Enums\BusinessHookErrorCode;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Services\WordPress\SideEffect\AutomationWordPressContext;
use App\Addons\SeoContentAi\Services\WordPressArticleSyncService;
use Illuminate\Support\Str;

final class SyncArticleToWordPressHookAction implements AutomationActionHandler
{
    public function __construct(
        private readonly WordPressArticleSyncService $syncService,
    ) {}

    public function handle(AutomationActionContext $context, array $input, array $settings): AutomationActionResult
    {
        if ($context->execution->id <= 0) {
            return AutomationActionResult::failure(
                BusinessHookErrorCode::ExecutionClaimFailed->value,
                'wordpress.article.sync requires automation_execution_id.',
            );
        }

        $articleId = (int) ($input['article_id'] ?? 0);
        if ($articleId <= 0) {
            return AutomationActionResult::failure('INVALID_ARTICLE_ID', 'article_id is required.');
        }

        $article = null;
        if ($context->subject instanceof SeoArticle && (int) $context->subject->getKey() === $articleId) {
            $article = $context->subject;
        } else {
            $article = SeoArticle::query()->find($articleId);
        }

        if (! $article instanceof SeoArticle) {
            return AutomationActionResult::failure(
                BusinessHookErrorCode::SubjectNotFound->value,
                "Article [{$articleId}] not found.",
            );
        }

        $idempotencyKey = hash(
            'sha256',
            ($context->execution->context['idempotency_key'] ?? $context->execution->idempotency_key)
            .'|wordpress.article.sync|'
            .$articleId,
        );

        $eventUuid = (string) ($context->businessEvent->event_uuid
            ?? $context->execution->context['event_uuid']
            ?? '');

        $sideEffect = new AutomationWordPressContext(
            automationExecutionId: (int) $context->execution->id,
            automationNodeExecutionId: $context->nodeExecutionId,
            businessEventUuid: $eventUuid !== '' ? $eventUuid : (string) Str::uuid(),
            idempotencyKey: $idempotencyKey,
            articleId: $articleId,
            siteId: (int) ($context->siteId ?? $article->site_id ?? 0),
            correlationId: (string) ($context->correlationId ?? $context->execution->execution_uuid ?? Str::uuid()),
        );

        $mode = (string) ($settings['mode'] ?? 'sync');
        $result = $mode === 'publish'
            ? $this->syncService->publishForArticle($article, $sideEffect)
            : $this->syncService->syncForArticle($article, $sideEffect);

        if (! ($result['success'] ?? false)) {
            return AutomationActionResult::failure(
                'WORDPRESS_SYNC_FAILED',
                (string) ($result['message'] ?? 'WordPress sync failed.'),
                [
                    'article_id' => $articleId,
                    'idempotency_key' => $idempotencyKey,
                    'wp_success' => false,
                ],
            );
        }

        return AutomationActionResult::success(
            output: [
                'article_id' => $articleId,
                'wp_post_id' => $result['wp_post_id'] ?? $article->wp_post_id ?? null,
                'message' => (string) ($result['message'] ?? 'synced'),
                'idempotency_key' => $idempotencyKey,
            ],
            message: 'WordPress sync completed.',
        );
    }
}
