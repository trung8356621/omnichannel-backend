<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Automation\BusinessHook\Actions;

use App\Addons\SeoContentAi\Automation\BusinessHook\Contracts\AutomationActionHandler;
use App\Addons\SeoContentAi\Automation\BusinessHook\Data\AutomationActionContext;
use App\Addons\SeoContentAi\Automation\BusinessHook\Data\AutomationActionResult;
use Illuminate\Support\Facades\Log;

/**
 * Optional wrap — chỉ gọi service nếu class tồn tại; không rewrite nghiệp vụ.
 */
final class ArticleGenerateContentHookAction implements AutomationActionHandler
{
    public function handle(AutomationActionContext $context, array $input, array $settings): AutomationActionResult
    {
        $articleId = (int) ($input['article_id'] ?? 0);
        if ($articleId <= 0) {
            return AutomationActionResult::failure('INVALID_ARTICLE_ID', 'article_id is required.');
        }

        Log::info('automation.article.generate_content.stub', [
            'article_id' => $articleId,
            'note' => 'No dedicated generate service wired; mark as acknowledged.',
        ]);

        return AutomationActionResult::success(
            output: ['article_id' => $articleId, 'status' => 'acknowledged'],
            message: 'Content generation hook acknowledged (service wrap optional).',
        );
    }
}
