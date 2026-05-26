<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Exceptions\PromptRunException;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Models\SeoPrompt;
use Illuminate\Support\Str;

final class ArticleEditorMediaAiService
{
    public function __construct(
        private readonly PromptRunnerService $promptRunner,
        private readonly SeoCreateArticleSettingsService $workflowSettings,
        private readonly SeoAnalyzerService $seoAnalyzer,
        private readonly SiteDomainPromptContextService $sitePromptContext,
    ) {
    }

    /**
     * @return array{url: string, media_type: 'image'}
     */
    public function generateImage(
        SeoArticle $article,
        string $selectionText,
        string $selectionHtml,
        string $userBrief,
    ): array {
        $prompt = $this->resolvePrompt(
            $this->workflowSettings->getCreateImagePromptId(),
            'Tạo ảnh',
            'image',
        );

        $variables = $this->buildVariables($article, $selectionText, $selectionHtml, $userBrief);

        return [
            'url' => $this->runMediaPrompt($prompt, $variables, 'image'),
            'media_type' => 'image',
        ];
    }

    /**
     * @return array{url: string, media_type: 'video'}
     */
    public function generateVideo(
        SeoArticle $article,
        string $selectionText,
        string $selectionHtml,
        string $userBrief,
    ): array {
        $prompt = $this->resolvePrompt(
            $this->workflowSettings->getCreateVideoPromptId(),
            'Tạo video',
            'video',
        );

        $variables = $this->buildVariables($article, $selectionText, $selectionHtml, $userBrief);

        return [
            'url' => $this->runMediaPrompt($prompt, $variables, 'video'),
            'media_type' => 'video',
        ];
    }

    private function resolvePrompt(?int $promptId, string $label, string $expectedTool): SeoPrompt
    {
        if ($promptId === null) {
            throw new \InvalidArgumentException(
                "Chưa cấu hình Prompt «{$label}». Vào SEO → Tùy chỉnh → Quy trình.",
            );
        }

        $prompt = SeoPrompt::query()->where('is_active', true)->find($promptId);
        if ($prompt === null) {
            throw new \InvalidArgumentException("Prompt «{$label}» không tồn tại hoặc đã tắt.");
        }

        $tool = strtolower(trim((string) ($prompt->tools ?? 'default')));
        if ($tool !== $expectedTool) {
            throw new \InvalidArgumentException(
                "Prompt «{$label}» phải dùng công cụ «{$expectedTool}» (hiện tại: {$tool}).",
            );
        }

        return $prompt;
    }

    /**
     * @param  array<string, string>  $variables
     */
    private function runMediaPrompt(SeoPrompt $prompt, array $variables, string $toolType): string
    {
        try {
            $result = $this->promptRunner->run($prompt, $variables, isTaskMode: false);
        } catch (PromptRunException $exception) {
            throw new \InvalidArgumentException($exception->getMessage(), 0, $exception);
        }

        $url = trim((string) ($result->output_text ?? ''));
        if ($url === '') {
            throw new \InvalidArgumentException(
                $toolType === 'image'
                    ? 'AI không trả về URL ảnh hợp lệ.'
                    : 'AI không trả về URL video hợp lệ.',
            );
        }

        $firstLine = trim(explode("\n", $url, 2)[0] ?? '');
        $isUrl = str_starts_with($firstLine, '/storage/')
            || (bool) preg_match('#^https?://#i', $firstLine);

        if (! $isUrl) {
            throw new \InvalidArgumentException(
                $toolType === 'image'
                    ? 'Kết quả không phải URL ảnh (/storage/ hoặc https).'
                    : 'Kết quả không phải URL video (/storage/ hoặc https).',
            );
        }

        return $firstLine;
    }

    /**
     * @return array<string, string>
     */
    private function buildVariables(
        SeoArticle $article,
        string $selectionText,
        string $selectionHtml,
        string $userBrief,
    ): array {
        $article->loadMissing(['site', 'keywords', 'articleMetas']);

        $postTitle = trim((string) ($article->title ?? ''));
        $focusKeyword = $this->seoAnalyzer->resolveFocusKeywordForArticle($article) ?? '';
        $bodyPlain = trim(strip_tags((string) ($article->body ?? '')));

        $selectionText = trim($selectionText);
        $selectionHtml = trim($selectionHtml);
        $userBrief = trim($userBrief);

        $input = $userBrief;
        if ($selectionText !== '') {
            $input = $input !== ''
                ? $input . "\n\n---\nĐoạn ngữ cảnh:\n" . $selectionText
                : $selectionText;
        }

        $variables = array_merge(
            [
                'post_title' => $postTitle,
                'post_content' => Str::limit($bodyPlain, 3000),
                'focus_keyword' => $focusKeyword,
                'selected_text' => $selectionText,
                'selected_html' => $selectionHtml,
                'user_brief' => $userBrief,
                'input' => $input,
            ],
            $this->sitePromptContext->promptVariablesForSite($article->site),
        );

        if ((int) $article->id > 0) {
            $variables['article_id'] = (string) (int) $article->id;
        }

        return $variables;
    }
}
