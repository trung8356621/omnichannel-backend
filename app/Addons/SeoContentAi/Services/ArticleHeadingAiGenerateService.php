<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Models\SeoArticleHeading;
use RuntimeException;

/**
 * Sinh lại text heading bằng AI (Gemini/Claude).
 *
 * TODO: Prompt chi tiết sẽ được bổ sung ở giai đoạn sau —
 * hiện tại chỉ dựng cấu trúc method theo yêu cầu.
 */
class ArticleHeadingAiGenerateService
{
    /**
     * @throws RuntimeException khi chưa cấu hình prompt AI
     */
    public function generateHeadingText(SeoArticle $article, SeoArticleHeading $heading): string
    {
        // Dự kiến: build prompt từ title bài viết + heading hiện tại + các heading anh em,
        // gọi PromptRunner (Gemini/Claude) rồi trả về text mới.
        throw new RuntimeException(
            'Chức năng AI gen heading chưa được cấu hình prompt. Vui lòng thử lại sau.',
        );
    }
}
