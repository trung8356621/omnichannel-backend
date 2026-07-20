<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Automation\Modules\Content;

use App\Addons\SeoContentAi\Automation\BusinessHook\Actions\ArticleGenerateContentHookAction;
use App\Addons\SeoContentAi\Automation\BusinessHook\Data\AutomationActionDefinition;
use App\Addons\SeoContentAi\Automation\BusinessHook\Data\BusinessEventDefinition;
use App\Addons\SeoContentAi\Automation\BusinessHook\Enums\AutomationActionCode;
use App\Addons\SeoContentAi\Automation\BusinessHook\Enums\AutomationQueueName;
use App\Addons\SeoContentAi\Automation\BusinessHook\Enums\BusinessEventName;
use App\Addons\SeoContentAi\Automation\Platform\AutomationModuleContext;
use App\Addons\SeoContentAi\Automation\Platform\Contracts\AutomationModuleProvider;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Models\SeoProjectRun;
use App\Addons\SeoContentAi\Models\SeoProjectTask;

final class ContentAutomationModuleProvider implements AutomationModuleProvider
{
    public function id(): string
    {
        return 'content';
    }

    public function register(AutomationModuleContext $context): void
    {
        foreach ([
            [BusinessEventName::ArticleCreated, SeoArticle::class, 'content', ['article_id' => true, 'site_id' => false, 'post_type' => false, 'status' => false]],
            [BusinessEventName::ArticleContentUpdated, SeoArticle::class, 'content', ['article_id' => true, 'site_id' => false]],
            [BusinessEventName::ArticleCompleted, SeoArticle::class, 'content', ['article_id' => true, 'site_id' => false, 'project_id' => false, 'status' => false]],
            [BusinessEventName::ArticleArchived, SeoArticle::class, 'content', ['article_id' => true]],
            [BusinessEventName::ArticleRestored, SeoArticle::class, 'content', ['article_id' => true]],
            [BusinessEventName::ArticleDeleted, SeoArticle::class, 'content', ['article_id' => true, 'site_id' => false]],
            [BusinessEventName::ArticlePublishRequested, SeoArticle::class, 'content', ['article_id' => true, 'site_id' => false]],

            [BusinessEventName::ContentProjectTaskCreated, SeoProjectTask::class, 'project', ['task_id' => true, 'project_id' => false, 'site_id' => false]],
            [BusinessEventName::ContentProjectTaskUpdated, SeoProjectTask::class, 'project', ['task_id' => true]],
            [BusinessEventName::ContentProjectTaskCompleted, SeoProjectTask::class, 'project', ['task_id' => true, 'article_id' => false, 'project_id' => false]],
            [BusinessEventName::ContentProjectTaskFailed, SeoProjectTask::class, 'project', ['task_id' => true, 'project_id' => false]],
            [BusinessEventName::ContentProjectTaskArchived, SeoProjectTask::class, 'project', ['task_id' => true]],

            [BusinessEventName::ContentProjectRunStarted, SeoProjectRun::class, 'project', ['run_id' => true, 'project_id' => false]],
            [BusinessEventName::ContentProjectRunCompleted, SeoProjectRun::class, 'project', ['run_id' => true, 'project_id' => false]],
            [BusinessEventName::ContentProjectRunFailed, SeoProjectRun::class, 'project', ['run_id' => true, 'project_id' => false]],
        ] as [$enum, $subject, $module, $fields]) {
            /** @var BusinessEventName $enum */
            $schema = [];
            foreach ($fields as $field => $required) {
                $schema[$field] = ['type' => 'mixed', 'required' => (bool) $required];
            }

            $context->events->register(new BusinessEventDefinition(
                name: $enum->value,
                subject: $subject,
                payloadSchema: $schema,
                description: $enum->value,
                module: $module,
            ));
        }

        $context->actions->register(new AutomationActionDefinition(
            actionCode: AutomationActionCode::ArticleGenerateContent->value,
            handlerClass: ArticleGenerateContentHookAction::class,
            inputRules: [
                'article_id' => ['type' => 'integer', 'required' => true],
            ],
            settingsRules: [],
            description: 'Wrap existing content generation service when available.',
            isAsyncSafe: true,
            timeout: 180,
            module: 'content',
            defaultQueue: AutomationQueueName::External->value,
            rateLimitKey: 'ai',
            maxAttemptsPerMinute: 20,
            fieldMeta: [
                'article_id' => ['label' => 'Article ID', 'type' => 'integer', 'source' => 'input'],
            ],
        ));
    }
}
