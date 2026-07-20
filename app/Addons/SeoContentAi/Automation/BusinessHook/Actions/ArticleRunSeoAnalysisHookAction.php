<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Automation\BusinessHook\Actions;

use App\Addons\SeoContentAi\Automation\BusinessHook\Contracts\AutomationActionHandler;
use App\Addons\SeoContentAi\Automation\BusinessHook\Data\AutomationActionContext;
use App\Addons\SeoContentAi\Automation\BusinessHook\Data\AutomationActionResult;
use App\Addons\SeoContentAi\Automation\Data\ActionContext as LegacyActionContext;
use App\Addons\SeoContentAi\Automation\Runtime\ActionRunner;
use Illuminate\Support\Str;

final class ArticleRunSeoAnalysisHookAction implements AutomationActionHandler
{
    public function __construct(
        private readonly ActionRunner $actionRunner,
    ) {}

    public function handle(AutomationActionContext $context, array $input, array $settings): AutomationActionResult
    {
        $articleId = (int) ($input['article_id'] ?? 0);
        if ($articleId <= 0) {
            return AutomationActionResult::failure('INVALID_ARTICLE_ID', 'article_id is required.');
        }

        try {
            $legacyContext = new LegacyActionContext(
                executionId: (string) Str::uuid(),
                correlationId: $context->correlationId ?? (string) Str::uuid(),
                causationId: $context->execution->execution_uuid,
                origin: 'business_hook',
                actorId: $context->actorId,
                teamId: null,
                siteId: $context->siteId,
                connectionId: null,
                locale: null,
                dryRun: false,
            );

            $result = $this->actionRunner->run('seo.audit.run', $legacyContext, [
                'article_id' => $articleId,
            ]);

            if (! $result->success) {
                $code = (string) ($result->error['code'] ?? 'SEO_AUDIT_FAILED');
                $message = (string) ($result->error['message'] ?? 'SEO audit failed.');

                return AutomationActionResult::failure($code, $message, $result->output);
            }

            return AutomationActionResult::success($result->output, 'SEO analysis completed.');
        } catch (\Throwable $e) {
            return AutomationActionResult::failure('SEO_AUDIT_EXCEPTION', $e->getMessage());
        }
    }
}
