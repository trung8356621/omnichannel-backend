<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Automation\Modules\WordPress;

use App\Addons\SeoContentAi\Automation\BusinessHook\Actions\PublishWordPressCommentReviewHookAction;
use App\Addons\SeoContentAi\Automation\BusinessHook\Actions\QueuePendingProductReviewsHookAction;
use App\Addons\SeoContentAi\Automation\BusinessHook\Actions\ScheduleGeneratedProductReviewsHookAction;
use App\Addons\SeoContentAi\Automation\BusinessHook\Actions\SyncArticleToWordPressHookAction;
use App\Addons\SeoContentAi\Automation\BusinessHook\Data\AutomationActionDefinition;
use App\Addons\SeoContentAi\Automation\BusinessHook\Data\BusinessEventDefinition;
use App\Addons\SeoContentAi\Automation\BusinessHook\Enums\AutomationActionCode;
use App\Addons\SeoContentAi\Automation\BusinessHook\Enums\AutomationQueueName;
use App\Addons\SeoContentAi\Automation\BusinessHook\Enums\BusinessEventName;
use App\Addons\SeoContentAi\Automation\Platform\AutomationModuleContext;
use App\Addons\SeoContentAi\Automation\Platform\Contracts\AutomationModuleProvider;
use App\Addons\SeoContentAi\Models\SeoArticle;

final class WordPressAutomationModuleProvider implements AutomationModuleProvider
{
    public function id(): string
    {
        return 'wordpress';
    }

    public function register(AutomationModuleContext $context): void
    {
        foreach ([
            [BusinessEventName::WordpressSyncRequested, ['article_id' => true, 'site_id' => false]],
            [BusinessEventName::WordpressSyncStarted, ['article_id' => true, 'site_id' => false]],
            [BusinessEventName::WordpressSynced, ['article_id' => true, 'site_id' => false]],
            [BusinessEventName::WordpressSyncFailed, ['article_id' => true, 'site_id' => false]],
            [BusinessEventName::WordpressPostDeleted, ['article_id' => true, 'site_id' => false, 'wp_post_id' => false]],
            [BusinessEventName::WordpressCommentReviewPublished, ['review_id' => true, 'article_id' => true, 'wp_post_id' => false, 'wp_comment_id' => false]],
            [BusinessEventName::WordpressCommentReviewPublishFailed, ['review_id' => true, 'article_id' => true, 'error' => false]],
        ] as [$enum, $fields]) {
            $context->events->register($this->eventDef($enum, $fields));
        }

        $context->actions->register(new AutomationActionDefinition(
            actionCode: AutomationActionCode::WordpressArticleSync->value,
            handlerClass: SyncArticleToWordPressHookAction::class,
            inputRules: [
                'article_id' => ['type' => 'integer', 'required' => true],
            ],
            settingsRules: [
                'mode' => ['type' => 'string', 'required' => false],
            ],
            description: 'Sync article to WordPress via existing sync service.',
            isAsyncSafe: true,
            timeout: 120,
            module: 'wordpress',
            defaultQueue: AutomationQueueName::External->value,
            rateLimitKey: 'wordpress',
            maxAttemptsPerMinute: 30,
            supportsTest: false,
            fieldMeta: [
                'article_id' => ['label' => 'Article ID', 'type' => 'integer', 'source' => 'input'],
                'mode' => ['label' => 'Sync mode', 'type' => 'select', 'source' => 'settings', 'options' => ['sync', 'publish']],
            ],
            supportsManualTrigger: true,
            manualPermission: 'wordpress.sync',
            manualLabel: 'Đồng bộ WordPress',
            manualDescription: 'Thực thi thủ công qua Automation Engine (wordpress.article.sync).',
            manualConfirmation: 'Đồng bộ bài viết lên WordPress ngay?',
            manualIdempotencyScope: 'subject',
            manualEnabled: true,
        ));

        $context->actions->register(new AutomationActionDefinition(
            actionCode: AutomationActionCode::WordpressCommentReviewPublish->value,
            handlerClass: PublishWordPressCommentReviewHookAction::class,
            inputRules: [
                'site_id' => ['type' => 'integer', 'required' => true],
                'connection_id' => ['type' => 'integer', 'required' => true],
                'article_id' => ['type' => 'integer', 'required' => true],
                'review_id' => ['type' => 'integer', 'required' => true],
                'wp_post_id' => ['type' => 'integer', 'required' => false],
                'publish_intent' => ['type' => 'string', 'required' => true],
            ],
            settingsRules: [],
            description: 'Publish one local product review to WordPress virtual-comments meta.',
            isAsyncSafe: true,
            timeout: 90,
            module: 'wordpress',
            defaultQueue: AutomationQueueName::External->value,
            rateLimitKey: 'wordpress',
            maxAttemptsPerMinute: 60,
            supportsTest: false,
            fieldMeta: [
                'review_id' => ['label' => 'Review ID', 'type' => 'integer', 'source' => 'input'],
                'article_id' => ['label' => 'Article ID', 'type' => 'integer', 'source' => 'input'],
                'publish_intent' => ['label' => 'Publish intent', 'type' => 'select', 'source' => 'input', 'options' => [
                    'generated_review', 'manual_publish', 'retry_failed', 'publish_after_article',
                ]],
            ],
            supportsManualTrigger: false,
            manualPermission: 'wordpress.sync',
            manualLabel: 'Publish product review',
            manualDescription: 'Internal — publish via Automation schedule only.',
            manualConfirmation: 'Xuất bản review này lên WordPress?',
            manualIdempotencyScope: 'subject',
            manualEnabled: false,
        ));

        $maxDelayMeta = [
            'max_delay_time' => [
                'label' => 'Thời gian trì hoãn tối đa',
                'type' => 'integer',
                'source' => 'settings',
                'description' => 'Mỗi review sẽ được đăng ngẫu nhiên trong khoảng từ 1 phút đến thời gian tối đa đã cấu hình.',
            ],
        ];

        $context->actions->register(new AutomationActionDefinition(
            actionCode: AutomationActionCode::ArticleProductReviewsQueuePending->value,
            handlerClass: QueuePendingProductReviewsHookAction::class,
            inputRules: [
                'article_id' => ['type' => 'integer', 'required' => true],
            ],
            settingsRules: [
                'max_delay_time' => ['type' => 'integer', 'required' => false, 'minimum' => 0, 'maximum' => 1440],
            ],
            description: 'Reconcile pending product reviews after article WordPress sync and schedule delayed publish.',
            isAsyncSafe: true,
            timeout: 60,
            module: 'wordpress',
            defaultQueue: AutomationQueueName::External->value,
            rateLimitKey: 'wordpress',
            maxAttemptsPerMinute: 60,
            supportsTest: false,
            fieldMeta: array_merge([
                'article_id' => ['label' => 'Article ID', 'type' => 'integer', 'source' => 'input'],
            ], $maxDelayMeta),
            supportsManualTrigger: false,
            manualEnabled: false,
        ));

        $context->actions->register(new AutomationActionDefinition(
            actionCode: AutomationActionCode::ArticleProductReviewsScheduleGenerated->value,
            handlerClass: ScheduleGeneratedProductReviewsHookAction::class,
            inputRules: [
                'article_id' => ['type' => 'integer', 'required' => true],
                'review_ids' => ['type' => 'array', 'required' => false],
            ],
            settingsRules: [
                'max_delay_time' => ['type' => 'integer', 'required' => false, 'minimum' => 0, 'maximum' => 1440],
            ],
            description: 'Schedule generated product reviews for WordPress publish (random delay per review).',
            isAsyncSafe: true,
            timeout: 60,
            module: 'wordpress',
            defaultQueue: AutomationQueueName::External->value,
            rateLimitKey: 'wordpress',
            maxAttemptsPerMinute: 60,
            supportsTest: false,
            fieldMeta: array_merge([
                'article_id' => ['label' => 'Article ID', 'type' => 'integer', 'source' => 'input'],
                'review_ids' => ['label' => 'Review IDs', 'type' => 'array', 'source' => 'input'],
            ], $maxDelayMeta),
            supportsManualTrigger: false,
            manualEnabled: false,
        ));
    }

    /**
     * @param  array<string, bool>  $fields
     */
    private function eventDef(BusinessEventName $enum, array $fields): BusinessEventDefinition
    {
        $schema = [];
        foreach ($fields as $field => $required) {
            $schema[$field] = ['type' => 'mixed', 'required' => $required];
        }

        return new BusinessEventDefinition(
            name: $enum->value,
            subject: SeoArticle::class,
            payloadSchema: $schema,
            description: $enum->value,
            module: 'wordpress',
        );
    }
}
