<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Exceptions\PromptRunException;
use App\Addons\SeoContentAi\Models\PromptResult;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Models\SeoArticleHeading;
use App\Addons\SeoContentAi\Models\SeoPrompt;
use App\Addons\SeoContentAi\Support\ArticlePostTypeResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Sinh lại text heading Outline bằng prompt cấu hình tại SEO → Tùy chỉnh → Quy trình.
 */
final class ArticleHeadingAiGenerateService
{
    public function __construct(
        private readonly SeoCreateArticleSettingsService $workflowSettings,
        private readonly PromptRunnerService $promptRunner,
        private readonly SeoPromptSettingsService $promptSettings,
        private readonly SeoAnalyzerService $seoAnalyzer,
        private readonly SiteDomainPromptContextService $sitePromptContext,
        private readonly ArticleFaqPromptVariablesService $articlePromptVariables,
        private readonly PromptResultLinkService $promptResultLinks,
    ) {
    }

    /**
     * @throws RuntimeException
     */
    public function generateHeadingText(SeoArticle $article, SeoArticleHeading $heading): string
    {
        $prompt = $this->resolvePrompt();
        $article->loadMissing(['site', 'headings']);

        $focusKeyword = trim($this->seoAnalyzer->resolveFocusKeywordForArticle($article) ?? '');
        $postType = ArticlePostTypeResolver::resolve($article);
        $promptVars = $this->promptSettings->promptVariables($postType);
        $outlineMarkdown = $this->buildOutlineMarkdown($article->headings);

        $variables = array_merge(
            $this->articlePromptVariables->buildForArticle($article),
            $promptVars,
            [
                'focus_keyword' => $focusKeyword,
                'heading_text' => trim((string) $heading->heading_text),
                'heading_level' => (string) ((int) $heading->level),
                'outline' => $outlineMarkdown,
                'outline_markdown' => $outlineMarkdown,
            ],
        );

        if ($focusKeyword !== '') {
            $variables['input'] = $focusKeyword;
        }

        $variables['tone'] = $this->sitePromptContext->resolveToneForSite(
            $article->site,
            $promptVars['tone'] ?? '',
        );

        try {
            $result = $this->promptRunner->run($prompt, $variables);
        } catch (PromptRunException $exception) {
            throw new RuntimeException($exception->getMessage(), 0, $exception);
        }

        $this->linkPromptResultToArticle($article, $prompt, $result, $heading);

        $text = $this->parseHeadingOutput((string) ($result->output_text ?? ''));
        if ($text === '') {
            throw new RuntimeException(
                'AI không trả về heading hợp lệ. Kiểm tra prompt — đầu ra nên là một dòng tiêu đề (plain text hoặc Markdown H2–H4).',
            );
        }

        return $text;
    }

    private function resolvePrompt(): SeoPrompt
    {
        $promptId = $this->workflowSettings->getOutlineHeadingRegeneratorPromptId();
        if ($promptId === null) {
            throw new RuntimeException(
                __('seo-content-ai::filament.article_edit.outline_heading_generate_no_prompt'),
            );
        }

        $prompt = SeoPrompt::query()->where('is_active', true)->find($promptId);
        if ($prompt === null) {
            throw new RuntimeException(
                __('seo-content-ai::filament.article_edit.outline_heading_generate_prompt_missing'),
            );
        }

        return $prompt;
    }

    /**
     * @param  Collection<int, SeoArticleHeading>  $headings
     */
    private function buildOutlineMarkdown(Collection $headings): string
    {
        $lines = [];
        foreach ($headings->sortBy('sort_order') as $row) {
            $level = max(2, min(6, (int) $row->level));
            $text = trim(preg_replace('/\s+/u', ' ', (string) $row->heading_text) ?? (string) $row->heading_text);
            if ($text === '') {
                continue;
            }

            $lines[] = str_repeat('#', $level).' '.$text;
        }

        return implode("\n", $lines);
    }

    private function parseHeadingOutput(string $raw): string
    {
        $text = trim($raw);
        if ($text === '') {
            return '';
        }

        if (preg_match('/```(?:markdown|md|text)?\s*([\s\S]*?)```/iu', $text, $match) === 1) {
            $text = trim((string) ($match[1] ?? ''));
        }

        foreach (preg_split('/\r\n|\r|\n/u', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $line = preg_replace('/^#{1,6}\s+/u', '', $line) ?? $line;
            $line = trim($line, " \t\"'“”‘’");

            return Str::limit($line, 255, '');
        }

        return '';
    }

    private function linkPromptResultToArticle(
        SeoArticle $article,
        SeoPrompt $prompt,
        PromptResult $result,
        SeoArticleHeading $heading,
    ): void {
        $resultId = (int) $result->getKey();
        if ($resultId <= 0) {
            return;
        }

        $this->promptResultLinks->linkPromptResult(
            promptResultId: $resultId,
            articleId: (int) $article->id,
            source: 'outline_heading_regenerate',
            workflowStepTitle: 'Regenerate outline heading (AI)',
            meta: [
                'prompt_id' => (int) $prompt->id,
                'prompt_name' => (string) ($prompt->name ?? ''),
                'heading_id' => (int) $heading->id,
                'heading_level' => (int) $heading->level,
                'status' => (string) ($result->status ?? ''),
            ],
        );
    }
}
