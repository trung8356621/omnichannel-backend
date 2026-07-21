<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Automation\BusinessHook\Actions;

use App\Addons\SeoContentAi\Automation\BusinessHook\Contracts\AutomationActionHandler;
use App\Addons\SeoContentAi\Automation\BusinessHook\Data\AutomationActionContext;
use App\Addons\SeoContentAi\Automation\BusinessHook\Data\AutomationActionResult;
use App\Addons\SeoContentAi\Automation\BusinessHook\Enums\BusinessHookErrorCode;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Services\WordPress\ArticleWordPressBusinessSequence;

/**
 * product-review.create — local pending only, shared ProductReviewCreationPolicy.
 */
final class CreateProductReviewsHookAction implements AutomationActionHandler
{
    public function __construct(
        private readonly ArticleWordPressBusinessSequence $sequence,
    ) {}

    public function handle(AutomationActionContext $context, array $input, array $settings): AutomationActionResult
    {
        $articleId = (int) ($input['article_id'] ?? $context->subject?->getKey() ?? 0);
        if ($articleId <= 0) {
            return AutomationActionResult::failure('INVALID_ARTICLE_ID', 'article_id is required.');
        }

        $article = SeoArticle::query()->find($articleId);
        if (! $article instanceof SeoArticle) {
            return AutomationActionResult::failure(
                BusinessHookErrorCode::SubjectNotFound->value,
                "Article [{$articleId}] not found.",
            );
        }

        $result = $this->sequence->runCreate($article, $settings);

        return AutomationActionResult::success(
            $result,
            (string) ($result['status'] ?? 'completed') === 'skipped'
                ? 'Skipped: '.(string) ($result['reason'] ?? 'policy')
                : 'Product reviews created locally.',
        );
    }
}
