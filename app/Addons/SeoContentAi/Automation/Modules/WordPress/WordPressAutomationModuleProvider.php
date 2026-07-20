<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Automation\Modules\WordPress;

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
