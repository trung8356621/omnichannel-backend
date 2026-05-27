<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Jobs\GenerateMediaJob;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Models\SeoMedia;
use App\Addons\SeoContentAi\Models\SeoPrompt;
use Illuminate\Support\Str;

final class ArticleEditorMediaAiService
{
    public function __construct(
        private readonly SeoCreateArticleSettingsService $workflowSettings,
        private readonly SeoAnalyzerService $seoAnalyzer,
        private readonly SiteDomainPromptContextService $sitePromptContext,
    ) {
    }

    /**
     * @return array{url: string, media_type: 'image', seo_media_id: int, status: string}
     */
    public function generateImage(
        SeoArticle $article,
        string $selectionText,
        string $selectionHtml,
        string $userBrief,
        string $editorBlockId = '',
    ): array {
        $prompt = $this->resolvePrompt(
            $this->workflowSettings->getCreateImagePromptId(),
            'Tạo ảnh',
            'image',
        );

        $variables = $this->filterVariablesForPrompt(
            $prompt,
            $this->buildVariables($article, $selectionText, $selectionHtml, $userBrief),
        );
        $this->reconcileStaleAiMediaJobs((int) $article->id);

        $placeholder = $this->findReusableProcessingJob($article, 'image', $editorBlockId)
            ?? $this->createPlaceholderMedia(
                $article,
                'image',
                (int) $prompt->id,
                $variables,
                $editorBlockId,
            );

        if ($placeholder->wasRecentlyCreated) {
            GenerateMediaJob::dispatch($placeholder->id, (int) $prompt->id, $variables, 'image')
                ->onQueue('media_generation');
        }

        return [
            'url' => (string) $placeholder->url,
            'media_type' => 'image',
            'seo_media_id' => (int) $placeholder->id,
            'status' => (string) $placeholder->status,
        ];
    }

    /**
     * @return array{url: string, media_type: 'video', seo_media_id: int, status: string}
     */
    public function generateVideo(
        SeoArticle $article,
        string $selectionText,
        string $selectionHtml,
        string $userBrief,
        string $editorBlockId = '',
    ): array {
        $prompt = $this->resolvePrompt(
            $this->workflowSettings->getCreateVideoPromptId(),
            'Tạo video',
            'video',
        );

        $variables = $this->filterVariablesForPrompt(
            $prompt,
            $this->buildVariables($article, $selectionText, $selectionHtml, $userBrief),
        );
        $this->reconcileStaleAiMediaJobs((int) $article->id);

        $placeholder = $this->findReusableProcessingJob($article, 'video', $editorBlockId)
            ?? $this->createPlaceholderMedia(
                $article,
                'video',
                (int) $prompt->id,
                $variables,
                $editorBlockId,
            );

        if ($placeholder->wasRecentlyCreated) {
            GenerateMediaJob::dispatch($placeholder->id, (int) $prompt->id, $variables, 'video')
                ->onQueue('media_generation');
        }

        return [
            'url' => (string) $placeholder->url,
            'media_type' => 'video',
            'seo_media_id' => (int) $placeholder->id,
            'status' => (string) $placeholder->status,
        ];
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

    /**
     * @param  array<string, string>  $variables
     * @return array<string, string>
     */
    private function filterVariablesForPrompt(SeoPrompt $prompt, array $variables): array
    {
        $allowedNames = collect(is_array($prompt->variables) ? $prompt->variables : [])
            ->map(static function (array $row): string {
                return trim((string) ($row['name'] ?? ''));
            })
            ->filter(static fn (string $name): bool => $name !== '')
            ->unique()
            ->values()
            ->all();

        if ($allowedNames === []) {
            $input = trim((string) ($variables['input'] ?? ''));

            return $input !== '' ? ['input' => $input] : [];
        }

        $filtered = [];

        foreach ($allowedNames as $name) {
            if (! array_key_exists($name, $variables)) {
                continue;
            }

            $value = trim((string) ($variables[$name] ?? ''));
            if ($value === '') {
                continue;
            }

            $filtered[$name] = $value;
        }

        if ($filtered === []) {
            $input = trim((string) ($variables['input'] ?? ''));
            if ($input !== '') {
                $filtered['input'] = $input;
            }
        }

        return $filtered;
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

    public function reconcileStaleAiMediaJobs(int $articleId): void
    {
        if ($articleId <= 0) {
            return;
        }

        SeoMedia::query()
            ->where('article_id', $articleId)
            ->whereIn('source', ['ai_prompt', 'ai_video_prompt'])
            ->where('status', 'processing')
            ->where(function ($query): void {
                $query->whereNull('prompt_id')
                    ->orWhereNull('prompt_variables');
            })
            ->update([
                'status' => 'failed',
                'error_message' => 'Job cũ không hợp lệ (thiếu cấu hình prompt). Hãy tạo ảnh mới.',
            ]);

        SeoMedia::query()
            ->where('article_id', $articleId)
            ->whereIn('source', ['ai_prompt', 'ai_video_prompt'])
            ->where('status', 'processing')
            ->where('updated_at', '<', now()->subMinutes(20))
            ->update([
                'status' => 'failed',
                'error_message' => 'Quá thời gian chờ xử lý AI. Kiểm tra queue worker rồi bấm Thử lại.',
            ]);
    }

    private function findReusableProcessingJob(
        SeoArticle $article,
        string $toolType,
        string $editorBlockId,
    ): ?SeoMedia {
        $source = $toolType === 'video' ? 'ai_video_prompt' : 'ai_prompt';
        $editorBlockId = trim($editorBlockId);

        $query = SeoMedia::query()
            ->where('article_id', (int) $article->id)
            ->where('source', $source)
            ->where('status', 'processing')
            ->whereNotNull('prompt_id')
            ->whereNotNull('prompt_variables');

        if ($editorBlockId !== '') {
            $query->where('editor_block_id', $editorBlockId);
        }

        return $query->orderByDesc('id')->first();
    }

    public function retryGeneration(SeoMedia $media): SeoMedia
    {
        if (! $media->isAiGenerationJob()) {
            throw new \InvalidArgumentException('Chỉ có thể thử lại media được tạo bởi AI.');
        }

        $promptId = (int) ($media->prompt_id ?? 0);
        $variables = $media->prompt_variables;

        if ($promptId <= 0 || ! is_array($variables) || $variables === []) {
            throw new \InvalidArgumentException('Thiếu cấu hình prompt để thử lại.');
        }

        $toolType = $media->aiToolType();

        $media->update([
            'url' => SeoMedia::placeholderLoadingUrl(),
            'path' => SeoMedia::placeholderLoadingPath(),
            'status' => 'processing',
            'error_message' => null,
        ]);

        GenerateMediaJob::dispatch($media->id, $promptId, $variables, $toolType)
            ->onQueue('media_generation');

        return $media->fresh();
    }

    /**
     * @param  array<string, string>  $variables
     */
    private function createPlaceholderMedia(
        SeoArticle $article,
        string $toolType,
        int $promptId,
        array $variables,
        string $editorBlockId,
    ): SeoMedia {
        if ((int) ($article->id ?? 0) <= 0) {
            throw new \InvalidArgumentException('Bài viết không hợp lệ — không thể tạo job AI.');
        }

        $slug = 'gen-' . now()->format('YmdHis') . '-' . random_int(100, 999);
        $editorBlockId = trim($editorBlockId);

        return SeoMedia::query()->create([
            'site_id' => (int) ($article->site_id ?? 0) > 0 ? (int) $article->site_id : null,
            'article_id' => (int) $article->id,
            'prompt_id' => $promptId,
            'prompt_variables' => $variables,
            'editor_block_id' => $editorBlockId !== '' ? Str::limit($editorBlockId, 64, '') : null,
            'filename' => $slug . '.svg',
            'slug' => $slug,
            'path' => SeoMedia::placeholderLoadingPath(),
            'url' => SeoMedia::placeholderLoadingUrl(),
            'source' => $toolType === 'video' ? 'ai_video_prompt' : 'ai_prompt',
            'status' => 'processing',
            'error_message' => null,
        ]);
    }
}
