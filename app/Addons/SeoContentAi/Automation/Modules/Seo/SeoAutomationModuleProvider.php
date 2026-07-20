<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Automation\Modules\Seo;

use App\Addons\SeoContentAi\Automation\BusinessHook\Actions\ArticleRunSeoAnalysisHookAction;
use App\Addons\SeoContentAi\Automation\BusinessHook\Data\AutomationActionDefinition;
use App\Addons\SeoContentAi\Automation\BusinessHook\Data\BusinessEventDefinition;
use App\Addons\SeoContentAi\Automation\BusinessHook\Enums\AutomationActionCode;
use App\Addons\SeoContentAi\Automation\BusinessHook\Enums\AutomationQueueName;
use App\Addons\SeoContentAi\Automation\BusinessHook\Enums\BusinessEventName;
use App\Addons\SeoContentAi\Automation\Platform\AutomationModuleContext;
use App\Addons\SeoContentAi\Automation\Platform\Contracts\AutomationModuleProvider;
use App\Addons\SeoContentAi\Models\SeoArticle;

final class SeoAutomationModuleProvider implements AutomationModuleProvider
{
    public function id(): string
    {
        return 'seo';
    }

    public function register(AutomationModuleContext $context): void
    {
        foreach ([
            BusinessEventName::SeoAnalysisStarted,
            BusinessEventName::SeoAnalysisCompleted,
            BusinessEventName::SeoAnalysisFailed,
        ] as $enum) {
            $context->events->register(new BusinessEventDefinition(
                name: $enum->value,
                subject: SeoArticle::class,
                payloadSchema: [
                    'article_id' => ['type' => 'mixed', 'required' => true],
                ],
                description: $enum->value,
                module: 'seo',
            ));
        }

        $context->actions->register(new AutomationActionDefinition(
            actionCode: AutomationActionCode::ArticleRunSeoAnalysis->value,
            handlerClass: ArticleRunSeoAnalysisHookAction::class,
            inputRules: [
                'article_id' => ['type' => 'integer', 'required' => true],
            ],
            settingsRules: [],
            description: 'Wrap existing SEO analysis / audit service.',
            isAsyncSafe: true,
            timeout: 120,
            module: 'seo',
            defaultQueue: AutomationQueueName::External->value,
            rateLimitKey: 'ai',
            maxAttemptsPerMinute: 20,
            fieldMeta: [
                'article_id' => ['label' => 'Article ID', 'type' => 'integer', 'source' => 'input'],
            ],
        ));
    }
}
