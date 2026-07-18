<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Exceptions\PromptRunException;
use App\Addons\SeoContentAi\Models\PromptResult;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Models\SeoPrompt;
use App\Addons\SeoContentAi\PromptHooks\Runtime\PromptHookCallerBridge;
use App\Addons\SeoContentAi\PromptHooks\Runtime\PromptHookExecutionInput;

/**
 * Sinh FAQ bằng prompt (renew_faq_prompt_id), bóc tách Markdown và đẩy vào panel FAQ.
 */
final class ArticleFaqGeneratorService
{
    public function __construct(
        private readonly SeoCreateArticleSettingsService $workflowSettings,
        private readonly PromptRunnerService $promptRunner,
        private readonly ArticleContentFaqService $contentFaq,
        private readonly ArticleFaqEditorService $faqEditor,
        private readonly WorkflowParserService $workflowParser,
        private readonly ArticleFaqExtractDebugService $extractDebug,
        private readonly ArticleFaqPromptVariablesService $faqPromptVariables,
        private readonly PromptResultLinkService $promptResultLinks,
        private readonly PromptHookCallerBridge $promptHookBridge,
    ) {
    }

    /**
     * @return array{
     *     faq_count: int,
     *     faqs: list<array<string, mixed>>,
     *     editor_html: string,
     * }
     */
    public function generate(SeoArticle $article, string $editorHtml = ''): array
    {
        $prompt = $this->resolvePrompt();
        $article->loadMissing(['site', 'faqs']);

        $variables = $this->faqPromptVariables->buildForArticle($article, [
            'faq_question' => '',
            'faq_answer' => '',
            'existing_faqs' => $this->summarizeExistingFaqs($article),
        ]);

        $envelope = PromptHookExecutionInput::fromArray([
            'context' => [
                'site_id' => (int) ($article->site_id ?? 0),
                'article_id' => (int) $article->id,
                'locale' => (string) ($article->language ?? ''),
            ],
            'input' => [
                'title' => (string) ($article->title ?? ''),
                'content_excerpt' => mb_substr(trim($editorHtml), 0, 50000),
                'language' => (string) ($variables['language'] ?? ''),
            ],
            'previous_outputs' => [],
            'settings' => [],
        ]);

        /** @var list<array<string, mixed>> $faqs */
        $faqs = $this->promptHookBridge->run(
            hookKey: 'article.faq.generate',
            version: '0.1.0',
            envelope: $envelope,
            legacyExecute: function () use ($prompt, $variables, $article): array {
                try {
                    $result = $this->promptRunner->run($prompt, $variables);
                } catch (PromptRunException $exception) {
                    throw new \InvalidArgumentException($exception->getMessage(), 0, $exception);
                }

                $this->linkPromptResultToArticle($article, $prompt, $result);

                $output = trim((string) ($result->output_text ?? ''));
                if ($output === '') {
                    throw new \InvalidArgumentException(
                        'AI không trả về nội dung FAQ. Kết quả prompt đã lưu — xem tại trang Prompts của bài.',
                    );
                }

                $parsed = $this->parseFaqsFromAiOutput($output);
                if ($parsed === []) {
                    throw new \InvalidArgumentException(
                        'Không bóc tách được FAQ từ kết quả AI. Kết quả prompt đã lưu — xem tại trang Prompts của bài, hoặc dùng «Import markdown FAQ (debug)».',
                    );
                }

                return $parsed;
            },
            mapHookResult: function ($runtimeResult): array {
                $value = $runtimeResult->output['value'] ?? null;
                if (is_string($value)) {
                    return $this->parseFaqsFromAiOutput($value);
                }
                if (! is_array($value)) {
                    return [];
                }
                // Accept list of FAQ objects or {faqs:[...]}
                if (isset($value['faqs']) && is_array($value['faqs'])) {
                    return array_values($value['faqs']);
                }

                return array_values($value);
            },
        );

        if ($faqs === []) {
            throw new \InvalidArgumentException(
                'Không bóc tách được FAQ từ kết quả AI. Kết quả prompt đã lưu — xem tại trang Prompts của bài, hoặc dùng «Import markdown FAQ (debug)».',
            );
        }

        // Domain persist — ngoài Hook runtime (caller responsibility).
        $this->faqEditor->saveFromEditor($article, $faqs);
        $this->extractDebug->clear($article);

        $baseHtml = trim($editorHtml);
        if ($baseHtml === '') {
            $baseHtml = trim((string) ($article->body ?? ''));
        }

        if ($baseHtml !== '') {
            app(ArticleFaqWordPressRestoreService::class)->persistWordPressSourceSnapshot($article, $baseHtml);
        }

        $newHtml = $this->contentFaq->injectFaqPlaceholderInEditorHtml($baseHtml);
        $this->contentFaq->persistArticleBodyHtml($article, $newHtml);

        $article = $article->fresh() ?? $article;

        return [
            'faq_count' => count($faqs),
            'faqs' => $this->faqEditor->payloadForArticle($article),
            'editor_html' => $newHtml,
        ];
    }

    private function linkPromptResultToArticle(SeoArticle $article, SeoPrompt $prompt, PromptResult $result): void
    {
        $resultId = (int) $result->getKey();
        if ($resultId <= 0) {
            return;
        }

        $this->promptResultLinks->linkPromptResult(
            promptResultId: $resultId,
            articleId: (int) $article->id,
            source: 'article_faq_generate',
            workflowStepTitle: 'Generate FAQ (AI)',
            meta: [
                'prompt_id' => (int) $prompt->id,
                'prompt_name' => (string) ($prompt->name ?? ''),
                'status' => (string) ($result->status ?? ''),
            ],
        );
    }

    private function resolvePrompt(): SeoPrompt
    {
        $promptId = $this->workflowSettings->getRenewFaqPromptId();
        if ($promptId === null) {
            throw new \InvalidArgumentException(
                'Chưa cấu hình Prompt làm mới FAQ. Vào SEO → Tùy chỉnh → Quy trình.',
            );
        }

        $prompt = SeoPrompt::query()->where('is_active', true)->find($promptId);
        if ($prompt === null) {
            throw new \InvalidArgumentException('Prompt làm mới FAQ không tồn tại hoặc đã tắt.');
        }

        return $prompt;
    }

    private function summarizeExistingFaqs(SeoArticle $article): string
    {
        $lines = [];
        foreach ($article->faqs as $faq) {
            $question = trim((string) $faq->question);
            $answer = trim(strip_tags((string) $faq->answer));
            if ($question === '') {
                continue;
            }
            $lines[] = 'Q: ' . $question . ($answer !== '' ? "\nA: " . $answer : '');
        }

        return implode("\n\n", $lines);
    }

    /**
     * @return list<array{question: string, answer: string, more?: string|null}>
     */
    private function parseFaqsFromAiOutput(string $output): array
    {
        $import = $this->contentFaq->convertMarkdownImport($output);
        $faqs = $import['faqs'];
        if ($faqs !== []) {
            return $faqs;
        }

        $parsed = $this->workflowParser->parseFaqsFromContent($output);

        $rows = [];
        foreach ($parsed as $faq) {
            if (! is_array($faq)) {
                continue;
            }
            $question = trim((string) ($faq['question'] ?? ''));
            $answer = trim((string) ($faq['answer'] ?? ''));
            if ($question === '' || $answer === '') {
                continue;
            }
            if (preg_match('/<[a-z][\s\S]*>/i', $answer) !== 1) {
                $answer = app(ArticleMarkdownToHtmlService::class)->toHtml($answer);
            }
            $rows[] = [
                'question' => $question,
                'answer' => $answer,
            ];
        }

        return $rows;
    }
}
