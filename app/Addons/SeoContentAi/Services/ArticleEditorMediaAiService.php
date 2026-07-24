<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Filament\Resources\PromptResource;
use App\Addons\SeoContentAi\Jobs\GenerateMediaJob;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Models\SeoMedia;
use App\Addons\SeoContentAi\Models\SeoProjectRun;
use App\Addons\SeoContentAi\Models\SeoProjectTask;
use App\Addons\SeoContentAi\Models\SeoPrompt;
use App\Addons\SeoContentAi\Support\ArticlePostTypeResolver;
use App\Addons\SeoContentAi\Support\ImageToolType;
use App\Addons\SeoContentAi\Support\PromptLoaiSanPhamVariable;
use App\Addons\SeoContentAi\Support\PromptPostProcessing;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

final class ArticleEditorMediaAiService
{
    public const PRODUCT_GALLERY_EDITOR_BLOCK_ID = 'product-gallery';

    public function __construct(
        private readonly SeoCreateArticleSettingsService $workflowSettings,
        private readonly EditorImageTaskResolverService $imageTaskResolver,
        private readonly SeoAnalyzerService $seoAnalyzer,
        private readonly SiteDomainPromptContextService $sitePromptContext,
        private readonly SeoPromptSettingsService $promptSettings,
    ) {}

    /**
     * @return array{url: string, media_type: 'image', seo_media_id: int, status: string}
     */
    public function generateImage(
        SeoArticle $article,
        string $selectionText,
        string $selectionHtml,
        string $userBrief,
        string $editorBlockId = '',
        string $target = 'editor',
        int $loaiSanPhamCategoryArticleId = 0,
        string $loaiSanPhamCustom = '',
    ): array {
        $target = trim($target);
        $editorBlockId = $this->resolveEditorBlockIdForTarget($target, $editorBlockId);
        [$loaiSanPhamCategoryArticleId, $loaiSanPhamCustom] = $this->resolveLoaiSanPhamInputs(
            $target,
            $userBrief,
            $loaiSanPhamCategoryArticleId,
            $loaiSanPhamCustom,
        );

        $lockKey = $this->generationLockKey($article, 'image', $editorBlockId);

        return $this->runWithGenerationLock($lockKey, function () use (
            $article,
            $selectionText,
            $selectionHtml,
            $userBrief,
            $editorBlockId,
            $target,
            $loaiSanPhamCategoryArticleId,
            $loaiSanPhamCustom,
        ): array {
            $config = $this->resolveEditorMediaConfig($target);
            $prompt = $config['prompt'];

            if ($target === 'product-gallery' && PromptLoaiSanPhamVariable::usesInPrompt($prompt)) {
                $siteId = (int) ($article->site_id ?? 0);
                $validation = app(PromptLoaiSanPhamOptionsService::class)->validateTestInputs(
                    $siteId,
                    $loaiSanPhamCategoryArticleId,
                    $loaiSanPhamCustom,
                );
                if (! ($validation['valid'] ?? false)) {
                    throw new \RuntimeException((string) ($validation['message'] ?? 'Thiếu loại sản phẩm (product_cat hoặc Custom).'));
                }
            }

            $mergeLoai = $this->shouldMergeLoaiSanPham(
                $prompt,
                $target,
                $loaiSanPhamCategoryArticleId,
                $loaiSanPhamCustom,
            );

            $variables = $this->attachEditorExecutionVariables(
                $this->filterVariablesForPrompt(
                    $prompt,
                    $this->buildVariables(
                        $article,
                        $selectionText,
                        $selectionHtml,
                        $userBrief,
                        $loaiSanPhamCategoryArticleId,
                        $loaiSanPhamCustom,
                        $mergeLoai,
                        $target,
                    ),
                ),
                $config,
            );
            $this->reconcileStaleAiMediaJobs((int) $article->id);
            $this->cancelProcessingJobsForBlock($article, 'image', $editorBlockId);

            $placeholder = $this->createPlaceholderMedia(
                $article,
                (string) $config['tool_type'],
                (int) $prompt->id,
                $variables,
                $editorBlockId,
            );

            $this->dispatchGenerateMediaJob(
                $placeholder,
                (int) $prompt->id,
                $variables,
                (string) $config['tool_type'],
                sync: false,
            );

            return [
                'url' => (string) $placeholder->url,
                'media_type' => 'image',
                'seo_media_id' => (int) $placeholder->id,
                'status' => (string) $placeholder->status,
            ];
        }, function () use ($article, $editorBlockId): array {
            $existing = $this->findReusableProcessingJob($article, 'image', $editorBlockId);
            if ($existing instanceof SeoMedia) {
                return [
                    'url' => (string) $existing->url,
                    'media_type' => 'image',
                    'seo_media_id' => (int) $existing->id,
                    'status' => (string) $existing->status,
                ];
            }

            throw new \RuntimeException('Yêu cầu tạo ảnh đang được xử lý, vui lòng thử lại sau vài giây.');
        });
    }

    /**
     * Same as generateImage but runs media_generation job synchronously (Content Project Run).
     *
     * @return array{url: string, media_type: 'image', seo_media_id: int, status: string}
     */
    public function generateImageBlocking(
        SeoArticle $article,
        string $selectionText,
        string $selectionHtml,
        string $userBrief,
        string $editorBlockId = '',
        string $target = 'editor',
        int $loaiSanPhamCategoryArticleId = 0,
        string $loaiSanPhamCustom = '',
    ): array {
        $target = trim($target);
        $editorBlockId = $this->resolveEditorBlockIdForTarget($target, $editorBlockId);
        [$loaiSanPhamCategoryArticleId, $loaiSanPhamCustom] = $this->resolveLoaiSanPhamInputs(
            $target,
            $userBrief,
            $loaiSanPhamCategoryArticleId,
            $loaiSanPhamCustom,
        );

        $lockKey = $this->generationLockKey($article, 'image', $editorBlockId);

        return $this->runWithGenerationLock($lockKey, function () use (
            $article,
            $selectionText,
            $selectionHtml,
            $userBrief,
            $editorBlockId,
            $target,
            $loaiSanPhamCategoryArticleId,
            $loaiSanPhamCustom,
        ): array {
            $config = $this->resolveEditorMediaConfig($target);
            $prompt = $config['prompt'];

            $mergeLoai = $this->shouldMergeLoaiSanPham(
                $prompt,
                $target,
                $loaiSanPhamCategoryArticleId,
                $loaiSanPhamCustom,
            );

            $variables = $this->attachEditorExecutionVariables(
                $this->filterVariablesForPrompt(
                    $prompt,
                    $this->buildVariables(
                        $article,
                        $selectionText,
                        $selectionHtml,
                        $userBrief,
                        $loaiSanPhamCategoryArticleId,
                        $loaiSanPhamCustom,
                        $mergeLoai,
                        $target,
                    ),
                ),
                $config,
            );
            $this->reconcileStaleAiMediaJobs((int) $article->id);
            $this->cancelProcessingJobsForBlock($article, 'image', $editorBlockId);

            $placeholder = $this->createPlaceholderMedia(
                $article,
                (string) $config['tool_type'],
                (int) $prompt->id,
                $variables,
                $editorBlockId,
            );

            $this->dispatchGenerateMediaJob(
                $placeholder,
                (int) $prompt->id,
                $variables,
                (string) $config['tool_type'],
                sync: true,
            );

            $fresh = $placeholder->fresh() ?? $placeholder;

            return [
                'url' => (string) $fresh->url,
                'media_type' => 'image',
                'seo_media_id' => (int) $fresh->id,
                'status' => (string) $fresh->status,
            ];
        }, function () use ($article, $editorBlockId): array {
            $existing = $this->findReusableProcessingJob($article, 'image', $editorBlockId);
            if ($existing instanceof SeoMedia) {
                return [
                    'url' => (string) $existing->url,
                    'media_type' => 'image',
                    'seo_media_id' => (int) $existing->id,
                    'status' => (string) $existing->status,
                ];
            }

            throw new \RuntimeException('Yêu cầu tạo ảnh đang được xử lý, vui lòng thử lại sau vài giây.');
        });
    }

    /**
     * @param  array<string, string>  $variables
     */
    private function dispatchGenerateMediaJob(
        SeoMedia $placeholder,
        int $promptId,
        array $variables,
        string $toolType,
        bool $sync,
    ): void {
        if ($sync) {
            GenerateMediaJob::dispatchSync(
                (int) $placeholder->id,
                $promptId,
                $variables,
                $toolType,
            );

            return;
        }

        GenerateMediaJob::dispatch(
            (int) $placeholder->id,
            $promptId,
            $variables,
            $toolType,
        )
            ->onQueue('media_generation')
            ->afterResponse();
    }

    /**
     * @return array{
     *     rendered: string,
     *     prompt_id: int,
     *     prompt_name: string,
     *     post_processing: array{
     *         split_enabled: bool,
     *         split_grid_size: int,
     *         split_rows: int,
     *         split_columns: int,
     *         expected_panels: int,
     *         resize_enabled: bool,
     *         resize_width: int|null,
     *         resize_height: int|null,
     *     },
     *     error?: string,
     * }
     */
    public function previewRenderedImagePrompt(
        SeoArticle $article,
        string $userBrief,
        string $target = 'editor',
        int $loaiSanPhamCategoryArticleId = 0,
        string $loaiSanPhamCustom = '',
        string $selectionText = '',
    ): array {
        $target = trim($target);
        [$loaiSanPhamCategoryArticleId, $loaiSanPhamCustom] = $this->resolveLoaiSanPhamInputs(
            $target,
            $userBrief,
            $loaiSanPhamCategoryArticleId,
            $loaiSanPhamCustom,
        );

        $prompt = $this->resolveEditorMediaConfig($target)['prompt'];

        $mergeLoai = $this->shouldMergeLoaiSanPham(
            $prompt,
            $target,
            $loaiSanPhamCategoryArticleId,
            $loaiSanPhamCustom,
        );
        $variables = $this->filterVariablesForPrompt(
            $prompt,
            $this->buildVariables(
                $article,
                $selectionText,
                '',
                $userBrief,
                $loaiSanPhamCategoryArticleId,
                $loaiSanPhamCustom,
                $mergeLoai,
                $target,
            ),
        );

        try {
            $rendered = app(PromptRunnerService::class)->compilePrompt($prompt, $variables);
        } catch (\Throwable $exception) {
            return [
                'rendered' => '',
                'prompt_id' => (int) $prompt->id,
                'prompt_name' => (string) ($prompt->name ?? ''),
                'post_processing' => PromptPostProcessing::fromPrompt($prompt),
                'error' => $exception->getMessage(),
            ];
        }

        return [
            'rendered' => $rendered,
            'prompt_id' => (int) $prompt->id,
            'prompt_name' => (string) ($prompt->name ?? ''),
            'post_processing' => PromptPostProcessing::fromPrompt($prompt),
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
        $lockKey = $this->generationLockKey($article, 'video', $editorBlockId);

        return $this->runWithGenerationLock($lockKey, function () use (
            $article,
            $selectionText,
            $selectionHtml,
            $userBrief,
            $editorBlockId
        ): array {
            $config = $this->resolveEditorVideoConfig();
            $prompt = $config['prompt'];

            $variables = $this->attachEditorExecutionVariables(
                $this->filterVariablesForPrompt(
                    $prompt,
                    $this->buildVariables($article, $selectionText, $selectionHtml, $userBrief, target: 'editor'),
                ),
                $config,
            );
            $this->reconcileStaleAiMediaJobs((int) $article->id);
            $this->cancelProcessingJobsForBlock($article, 'video', $editorBlockId);

            $placeholder = $this->createPlaceholderMedia(
                $article,
                'video',
                (int) $prompt->id,
                $variables,
                $editorBlockId,
            );

            GenerateMediaJob::dispatch($placeholder->id, (int) $prompt->id, $variables, 'video')
                ->onQueue('media_generation')
                ->afterResponse();

            return [
                'url' => (string) $placeholder->url,
                'media_type' => 'video',
                'seo_media_id' => (int) $placeholder->id,
                'status' => (string) $placeholder->status,
            ];
        }, function () use ($article, $editorBlockId): array {
            $existing = $this->findReusableProcessingJob($article, 'video', $editorBlockId);
            if ($existing instanceof SeoMedia) {
                return [
                    'url' => (string) $existing->url,
                    'media_type' => 'video',
                    'seo_media_id' => (int) $existing->id,
                    'status' => (string) $existing->status,
                ];
            }

            throw new \RuntimeException('Yêu cầu tạo video đang được xử lý, vui lòng thử lại sau vài giây.');
        });
    }

    /**
     * @return array<string, string>
     */
    private function buildVariables(
        SeoArticle $article,
        string $selectionText,
        string $selectionHtml,
        string $userBrief,
        int $loaiSanPhamCategoryArticleId = 0,
        string $loaiSanPhamCustom = '',
        bool $mergeLoaiSanPham = false,
        string $target = 'editor',
    ): array {
        $article->loadMissing(['site', 'articleMetas']);

        $postTitle = trim((string) ($article->title ?? ''));
        $focusKeyword = $this->seoAnalyzer->resolveFocusKeywordForArticle($article) ?? '';
        $bodyPlain = html_entity_decode(
            trim(strip_tags((string) ($article->body ?? ''))),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8',
        );
        $galleryDescription = $this->resolveGalleryDescription($article);
        $loaiSanPham = $this->resolveLoaiSanPham($article);

        $selectionText = trim($selectionText);
        $selectionHtml = trim($selectionHtml);
        if ($galleryDescription !== '') {
            $userBrief = str_replace('{{gallery_description}}', $galleryDescription, $userBrief);
        }
        $userBrief = $this->compactVariableValue($userBrief);

        $includeSelectionInPrompt = trim($target) === 'product-gallery';
        $promptSelectionText = $includeSelectionInPrompt ? $selectionText : '';
        $promptSelectionHtml = $includeSelectionInPrompt ? $selectionHtml : '';

        $input = trim($target) === 'product-gallery'
            ? $userBrief
            : $this->resolveEditorImageInput($userBrief);

        $postType = ArticlePostTypeResolver::resolve($article);
        $promptVars = $this->promptSettings->promptVariables($postType);
        $variables = array_merge(
            [
                'post_title' => $postTitle,
                'post_content' => Str::limit($bodyPlain, 3000),
                'focus_keyword' => $focusKeyword,
                'selected_text' => $promptSelectionText,
                'selected_html' => $promptSelectionHtml,
                'user_brief' => $userBrief,
                'gallery_description' => $galleryDescription,
                'loai_san_pham' => $loaiSanPham,
                'LOAI_SAN_PHAM' => $loaiSanPham,
                'input' => $input,
            ],
            $promptVars,
            $this->sitePromptContext->promptVariablesForSite($article->site),
        );
        $variables['tone'] = $this->sitePromptContext->resolveToneForSite(
            $article->site,
            $promptVars['tone'] ?? '',
        );

        if ((int) $article->id > 0) {
            $variables['article_id'] = (string) (int) $article->id;
        }

        if ($mergeLoaiSanPham) {
            $loaiInputs = [
                PromptLoaiSanPhamVariable::SITE_FIELD => (string) (int) ($article->site_id ?? 0),
                PromptLoaiSanPhamVariable::CATEGORY_FIELD => (string) $loaiSanPhamCategoryArticleId,
                PromptLoaiSanPhamVariable::CUSTOM_FIELD => $loaiSanPham !== ''
                    ? $loaiSanPham
                    : trim($loaiSanPhamCustom),
            ];
            $variables = array_merge($variables, PromptLoaiSanPhamVariable::mergeIntoVariables($loaiInputs));
            $variables = PromptLoaiSanPhamVariable::withAliases($variables);
        }

        return $variables;
    }

    private function resolveEditorImageInput(string $userBrief): string
    {
        return trim($userBrief);
    }

    /**
     * @param  array<string, string>  $variables
     * @return array<string, string>
     */
    private function filterVariablesForPrompt(SeoPrompt $prompt, array $variables): array
    {
        $fromDefinitions = collect(is_array($prompt->variables) ? $prompt->variables : [])
            ->map(static function (array $row): string {
                return trim((string) ($row['name'] ?? ''));
            })
            ->filter(static fn (string $name): bool => $name !== '');

        $fromMarkdown = collect(
            PromptResource::extractVariableNamesFromMarkdown((string) ($prompt->markdown_content ?? '')),
        );

        $allowedNames = $fromDefinitions
            ->merge($fromMarkdown)
            ->unique()
            ->values()
            ->all();

        if ($allowedNames === []) {
            $input = $this->compactVariableValue((string) ($variables['input'] ?? ''));

            return $input !== '' ? ['input' => $input] : [];
        }

        $filtered = [];

        foreach ($allowedNames as $name) {
            if (! array_key_exists($name, $variables)) {
                continue;
            }

            $value = $this->compactVariableValue((string) ($variables[$name] ?? ''));
            if ($value === '') {
                continue;
            }

            $filtered[$name] = $value;
        }

        if (isset($filtered[PromptLoaiSanPhamVariable::NAME])) {
            $filtered = PromptLoaiSanPhamVariable::withAliases($filtered);
        }

        if (! isset($filtered['input']) && in_array('input', $allowedNames, true)) {
            $input = $this->compactVariableValue((string) ($variables['input'] ?? ''));
            if ($input === '') {
                $input = $this->compactVariableValue($this->resolveEditorImageInput(
                    (string) ($variables['user_brief'] ?? ''),
                ));
            }
            if ($input !== '') {
                $filtered['input'] = $input;
            }
        }

        if ($filtered === []) {
            $input = $this->compactVariableValue((string) ($variables['input'] ?? ''));
            if ($input !== '') {
                $filtered['input'] = $input;
            }
        }

        return $filtered;
    }

    private function resolveGalleryDescription(SeoArticle $article): string
    {
        $article->loadMissing('articleMetas');

        $fromMeta = trim((string) ($article->articleMetas
            ->firstWhere('meta_key', 'gallery_description')?->meta_value ?? ''));
        if ($fromMeta !== '') {
            return $fromMeta;
        }

        $runMeta = $article->articleMetas->firstWhere('meta_key', 'content_project_run')?->meta_value;
        $runPayload = is_string($runMeta) && $runMeta !== ''
            ? json_decode($runMeta, true)
            : null;
        $taskId = is_array($runPayload) ? (int) ($runPayload['task_id'] ?? 0) : 0;
        if ($taskId > 0) {
            $task = SeoProjectTask::query()->find($taskId);
            $fromTask = $this->galleryDescriptionFromTask($task);
            if ($fromTask !== '') {
                return $fromTask;
            }
        }

        $articleId = (int) ($article->id ?? 0);
        if ($articleId <= 0) {
            return '';
        }

        $task = SeoProjectTask::query()
            ->where('article_id', $articleId)
            ->latest('id')
            ->first();
        $fromTask = $this->galleryDescriptionFromTask($task);
        if ($fromTask !== '') {
            return $fromTask;
        }

        $title = trim((string) ($article->title ?? ''));
        if ($title !== '') {
            $task = SeoProjectTask::query()
                ->where('source_content', $title)
                ->latest('id')
                ->first();
            $fromTask = $this->galleryDescriptionFromTask($task);
            if ($fromTask !== '') {
                return $fromTask;
            }
        }

        $runs = SeoProjectRun::query()
            ->latest('id')
            ->limit(200)
            ->get(['items']);
        foreach ($runs as $run) {
            $items = is_array($run->items) ? $run->items : [];
            foreach ($items as $item) {
                if (! is_array($item) || (int) ($item['article_id'] ?? 0) !== $articleId) {
                    continue;
                }

                $fromItem = trim((string) ($item['gallery_description'] ?? ''));
                if ($fromItem !== '') {
                    return $fromItem;
                }
            }
        }

        return '';
    }

    private function resolveLoaiSanPham(SeoArticle $article): string
    {
        $article->loadMissing('articleMetas');

        $fromMeta = trim((string) ($article->articleMetas
            ->firstWhere('meta_key', 'loai_san_pham')?->meta_value ?? ''));
        if ($fromMeta !== '') {
            return $fromMeta;
        }

        $runMeta = $article->articleMetas->firstWhere('meta_key', 'content_project_run')?->meta_value;
        $runPayload = is_string($runMeta) && $runMeta !== ''
            ? json_decode($runMeta, true)
            : null;
        $taskId = is_array($runPayload) ? (int) ($runPayload['task_id'] ?? 0) : 0;
        if ($taskId > 0) {
            $task = SeoProjectTask::query()->find($taskId);
            $fromTask = $this->loaiSanPhamFromTask($task);
            if ($fromTask !== '') {
                return $fromTask;
            }
        }

        $articleId = (int) ($article->id ?? 0);
        if ($articleId <= 0) {
            return '';
        }

        $task = SeoProjectTask::query()
            ->where('article_id', $articleId)
            ->latest('id')
            ->first();
        $fromTask = $this->loaiSanPhamFromTask($task);
        if ($fromTask !== '') {
            return $fromTask;
        }

        $title = trim((string) ($article->title ?? ''));
        if ($title !== '') {
            $task = SeoProjectTask::query()
                ->where('source_content', $title)
                ->latest('id')
                ->first();
            $fromTask = $this->loaiSanPhamFromTask($task);
            if ($fromTask !== '') {
                return $fromTask;
            }
        }

        $runs = SeoProjectRun::query()
            ->latest('id')
            ->limit(200)
            ->get(['items']);
        foreach ($runs as $run) {
            $items = is_array($run->items) ? $run->items : [];
            foreach ($items as $item) {
                if (! is_array($item) || (int) ($item['article_id'] ?? 0) !== $articleId) {
                    continue;
                }

                $fromItem = trim((string) ($item['loai_san_pham'] ?? ''));
                if ($fromItem !== '') {
                    return $fromItem;
                }
            }
        }

        return '';
    }

    private function loaiSanPhamFromTask(?SeoProjectTask $task): string
    {
        if (! $task instanceof SeoProjectTask) {
            return '';
        }

        if (! SeoProjectTask::isNewArticleType($task->type)) {
            return '';
        }

        if (SeoProjectTask::normalizePostType($task->post_type) !== SeoProjectTask::POST_TYPE_PRODUCT) {
            return '';
        }

        return trim((string) ($task->loai_san_pham ?? ''));
    }

    private function galleryDescriptionFromTask(?SeoProjectTask $task): string
    {
        if (! $task instanceof SeoProjectTask) {
            return '';
        }

        if (! SeoProjectTask::isNewArticleType($task->type)) {
            return '';
        }

        if (SeoProjectTask::normalizePostType($task->post_type) !== SeoProjectTask::POST_TYPE_PRODUCT) {
            return '';
        }

        return trim((string) ($task->description ?? ''));
    }

    private function shouldMergeLoaiSanPham(
        SeoPrompt $prompt,
        string $target,
        int $loaiSanPhamCategoryArticleId,
        string $loaiSanPhamCustom,
    ): bool {
        if (! PromptLoaiSanPhamVariable::usesInPrompt($prompt)) {
            return false;
        }

        if ($target === 'product-gallery') {
            return true;
        }

        return $loaiSanPhamCategoryArticleId > 0 || trim($loaiSanPhamCustom) !== '';
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function resolveLoaiSanPhamInputs(
        string $target,
        string $userBrief,
        int $loaiSanPhamCategoryArticleId,
        string $loaiSanPhamCustom,
    ): array {
        $userBrief = trim($userBrief);
        $loaiSanPhamCustom = trim($loaiSanPhamCustom);

        if (
            $target === 'product-gallery'
            && $loaiSanPhamCustom === ''
            && $loaiSanPhamCategoryArticleId <= 0
            && $userBrief !== ''
        ) {
            $loaiSanPhamCustom = $userBrief;
        }

        return [$loaiSanPhamCategoryArticleId, $loaiSanPhamCustom];
    }

    private function resolveEditorBlockIdForTarget(string $target, string $editorBlockId): string
    {
        if (trim($target) === 'product-gallery') {
            return self::PRODUCT_GALLERY_EDITOR_BLOCK_ID;
        }

        return trim($editorBlockId);
    }

    private function compactVariableValue(string $value): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", trim($value));
        $value = (string) preg_replace("/\n{2,}/u", "\n", $value);

        return trim($value);
    }

    private function generationLockKey(SeoArticle $article, string $toolType, string $editorBlockId): string
    {
        $articleId = (int) ($article->id ?? 0);
        $blockKey = trim($editorBlockId);
        if ($blockKey === '') {
            $blockKey = 'none';
        }

        return 'seo:ai-media-generate:'.$articleId.':'.$toolType.':'.sha1($blockKey);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function runWithGenerationLock(
        string $lockKey,
        callable $callback,
        ?callable $onLockTimeout = null,
    ): mixed {
        $store = Cache::getStore();
        if (! method_exists($store, 'lock')) {
            // Cache driver không hỗ trợ atomic lock (ví dụ array/file cũ).
            return $callback();
        }

        $lock = Cache::lock($lockKey, 8);

        try {
            return $lock->block(3, $callback);
        } catch (LockTimeoutException) {
            if ($onLockTimeout !== null) {
                return $onLockTimeout();
            }

            throw new \RuntimeException('Yêu cầu đang được xử lý, vui lòng thử lại sau vài giây.');
        } catch (\Throwable $exception) {
            $message = strtolower((string) $exception->getMessage());
            if (str_contains($message, 'lock') && str_contains($message, 'support')) {
                return $callback();
            }

            throw $exception;
        }
    }

    /**
     * @return array{
     *     source: string,
     *     prompt: SeoPrompt,
     *     task_id: int|null,
     *     tool_type: string,
     *     media_target: string,
     * }
     */
    private function resolveEditorMediaConfig(string $target): array
    {
        $target = trim($target);

        if ($target === 'product-gallery') {
            return [
                'source' => $this->workflowSettings->getCreateProductGallerySource(),
                'prompt' => $this->resolveConfiguredMediaSource(
                    source: $this->workflowSettings->getCreateProductGallerySource(),
                    promptId: $this->workflowSettings->getCreateProductGalleryImagePromptId(),
                    taskId: $this->workflowSettings->getCreateProductGalleryImageTaskId(),
                    label: 'Tạo ảnh Product gallery',
                    imagePipeline: true,
                ),
                'task_id' => $this->workflowSettings->getCreateProductGalleryImageTaskId(),
                'tool_type' => ImageToolType::Image->value,
                'media_target' => $target,
            ];
        }

        if ($target === 'typography' || $target === 'editor' || $target === '') {
            return [
                'source' => $this->workflowSettings->getCreateTypographyImageSource(),
                'prompt' => $this->resolveConfiguredMediaSource(
                    source: $this->workflowSettings->getCreateTypographyImageSource(),
                    promptId: $this->workflowSettings->getCreateTypographyImagePromptId(),
                    taskId: $this->workflowSettings->getCreateTypographyImageTaskId(),
                    label: 'Typography / Infographic',
                    imagePipeline: true,
                ),
                'task_id' => $this->workflowSettings->getCreateTypographyImageTaskId(),
                'tool_type' => ImageToolType::ImageTypography->value,
                'media_target' => $target === 'typography' ? 'typography' : 'editor',
            ];
        }

        // Legacy create_image chỉ còn cho target đặc thù (nếu có); editor mặc định = typography.
        return [
            'source' => $this->workflowSettings->getCreateImageSource(),
            'prompt' => $this->resolveConfiguredMediaSource(
                source: $this->workflowSettings->getCreateImageSource(),
                promptId: $this->workflowSettings->getLegacyCreateImagePromptId(),
                taskId: $this->workflowSettings->getCreateImageTaskId(),
                label: 'Tạo ảnh',
                imagePipeline: true,
            ),
            'task_id' => $this->workflowSettings->getCreateImageTaskId(),
            'tool_type' => ImageToolType::Image->value,
            'media_target' => $target,
        ];
    }

    /**
     * @return array{
     *     source: string,
     *     prompt: SeoPrompt,
     *     task_id: int|null,
     *     tool_type: string,
     *     media_target: string,
     * }
     */
    private function resolveEditorVideoConfig(): array
    {
        return [
            'source' => $this->workflowSettings->getCreateVideoSource(),
            'prompt' => $this->resolveConfiguredMediaSource(
                source: $this->workflowSettings->getCreateVideoSource(),
                promptId: $this->workflowSettings->getCreateVideoPromptId(),
                taskId: $this->workflowSettings->getCreateVideoWorkflowTaskId(),
                label: 'Tạo video',
                imagePipeline: false,
            ),
            'task_id' => $this->workflowSettings->getCreateVideoWorkflowTaskId(),
            'tool_type' => ImageToolType::Video->value,
            'media_target' => 'video',
        ];
    }

    /**
     * @param  array<string, mixed>  $variables
     * @param  array{source: string, prompt: SeoPrompt, task_id: int|null, tool_type: string, media_target: string}  $config
     * @return array<string, mixed>
     */
    private function attachEditorExecutionVariables(array $variables, array $config): array
    {
        $variables[SeoCreateArticleSettingsService::EDITOR_VAR_MEDIA_SOURCE] = (string) $config['source'];
        $variables[SeoCreateArticleSettingsService::EDITOR_VAR_MEDIA_TARGET] = (string) $config['media_target'];

        $taskId = $config['task_id'] ?? null;
        if (is_int($taskId) && $taskId > 0) {
            $variables[SeoCreateArticleSettingsService::EDITOR_VAR_WORKFLOW_TASK_ID] = (string) $taskId;
        }

        $prompt = $config['prompt'] ?? null;
        if ($prompt instanceof SeoPrompt && ImageToolType::fromMixed($config['tool_type'] ?? 'default')->isImagePipeline()) {
            $variables = PromptPostProcessing::attachSnapshotToVariables(
                $variables,
                PromptPostProcessing::fromPrompt($prompt),
            );
        }

        return $variables;
    }

    private function resolveEditorImagePrompt(string $target): SeoPrompt
    {
        return $this->resolveEditorMediaConfig($target)['prompt'];
    }

    private function resolveConfiguredMediaSource(
        string $source,
        ?int $promptId,
        ?int $taskId,
        string $label,
        bool $imagePipeline,
    ): SeoPrompt {
        if ($source === SeoCreateArticleSettingsService::SOURCE_WORKFLOW) {
            if ($taskId === null) {
                throw new \InvalidArgumentException(
                    "Chưa cấu hình Workflow «{$label}». Vào SEO → Settings → Workflows → chọn Typography / Infographic (hoặc Prompt).",
                );
            }

            // Editor workflow: full graph chạy trong GenerateMediaJob; đây chỉ resolve prompt tham chiếu.
            return $imagePipeline
                ? $this->imageTaskResolver->resolveImagePrompt($taskId)
                : $this->imageTaskResolver->resolveVideoPrompt($taskId);
        }

        return $this->resolvePrompt(
            $promptId,
            $label,
            $imagePipeline ? 'image' : 'video',
        );
    }

    private function resolvePrompt(?int $promptId, string $label, string $expectedTool): SeoPrompt
    {
        if ($promptId === null) {
            throw new \InvalidArgumentException(
                "Chưa cấu hình Prompt «{$label}». Vào SEO → Settings → Workflows → chọn Typography / Infographic (hoặc Workflow).",
            );
        }

        $prompt = SeoPrompt::query()->where('is_active', true)->find($promptId);
        if ($prompt === null) {
            throw new \InvalidArgumentException("Prompt «{$label}» không tồn tại hoặc đã tắt.");
        }

        $tool = ImageToolType::fromMixed($prompt->tools ?? 'default');
        $expected = ImageToolType::fromMixed($expectedTool);

        if ($expected->isImagePipeline()) {
            if (! $tool->isImagePipeline()) {
                throw new \InvalidArgumentException(
                    "Prompt «{$label}» phải dùng công cụ Image / Image (Typography) (hiện tại: {$tool->value}).",
                );
            }

            return $prompt;
        }

        if ($tool->value !== $expected->value) {
            throw new \InvalidArgumentException(
                "Prompt «{$label}» phải dùng công cụ «{$expected->value}» (hiện tại: {$tool->value}).",
            );
        }

        return $prompt;
    }

    public function reconcileStaleAiMediaJobs(int $articleId): void
    {
        if ($articleId <= 0) {
            return;
        }

        $invalidQuery = SeoMedia::query()
            ->where('article_id', $articleId)
            ->whereIn('source', ['ai_prompt', 'ai_video_prompt'])
            ->where('status', 'processing')
            ->where(function ($query): void {
                $query->whereNull('prompt_id')
                    ->orWhereNull('prompt_variables');
            });
        $invalidQuery->update([
            'status' => 'failed',
            'error_message' => 'Job cũ không hợp lệ (thiếu cấu hình prompt). Hãy tạo ảnh mới.',
        ]);

        $timeoutQuery = SeoMedia::query()
            ->where('article_id', $articleId)
            ->whereIn('source', ['ai_prompt', 'ai_video_prompt'])
            ->where('status', 'processing')
            ->where('updated_at', '<', now()->subMinutes(10));
        $timeoutQuery->update([
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
        } else {
            $query->where(function ($q): void {
                $q->whereNull('editor_block_id')
                    ->orWhere('editor_block_id', '');
            });
        }

        return $query->orderByDesc('id')->first();
    }

    private function cancelProcessingJobsForBlock(
        SeoArticle $article,
        string $toolType,
        string $editorBlockId,
    ): void {
        $source = $toolType === 'video' ? 'ai_video_prompt' : 'ai_prompt';
        $editorBlockId = trim($editorBlockId);

        $query = SeoMedia::query()
            ->where('article_id', (int) $article->id)
            ->where('source', $source)
            ->where('status', 'processing');

        if ($editorBlockId !== '') {
            $query->where('editor_block_id', $editorBlockId);
        } else {
            $query->where(function ($q): void {
                $q->whereNull('editor_block_id')
                    ->orWhere('editor_block_id', '');
            });
        }

        $query->update([
            'status' => 'failed',
            'error_message' => 'Job cũ đã được thay thế bởi yêu cầu tạo mới.',
        ]);
    }

    public function retryGeneration(SeoMedia $media, ?string $retryInput = null): SeoMedia
    {
        if (! $media->isAiGenerationJob()) {
            throw new \InvalidArgumentException('Chỉ có thể thử lại media được tạo bởi AI.');
        }

        $promptId = (int) ($media->prompt_id ?? 0);
        $variables = $media->prompt_variables;

        if ($promptId <= 0 || ! is_array($variables) || $variables === []) {
            throw new \InvalidArgumentException('Thiếu cấu hình prompt để thử lại.');
        }

        $retryInput = trim((string) $retryInput);
        if ($retryInput !== '') {
            $variables = $this->applyRetryInputToVariables($variables, $retryInput);
        }

        $toolType = $media->aiToolType();

        $media->update([
            'url' => SeoMedia::placeholderLoadingUrl(),
            'path' => SeoMedia::placeholderLoadingPath(),
            'status' => 'processing',
            'error_message' => null,
            'prompt_variables' => $variables,
        ]);

        GenerateMediaJob::dispatch($media->id, $promptId, $variables, $toolType)
            ->onQueue('media_generation')
            ->afterResponse();

        return $media->fresh();
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    private function applyRetryInputToVariables(array $variables, string $retryInput): array
    {
        $preferredKeys = ['prompt', 'input', 'content', 'text', 'description', 'image_prompt'];
        foreach ($preferredKeys as $key) {
            if (! array_key_exists($key, $variables) || ! is_string($variables[$key])) {
                continue;
            }

            $variables[$key] = $retryInput;

            return $variables;
        }

        foreach ($variables as $key => $value) {
            if (! is_string($value)) {
                continue;
            }

            $variables[$key] = $retryInput;

            return $variables;
        }

        $variables['input'] = $retryInput;

        return $variables;
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

        $slug = 'gen-'.now()->format('YmdHis').'-'.random_int(100, 999);
        $editorBlockId = trim($editorBlockId);

        return SeoMedia::query()->create([
            'site_id' => (int) ($article->site_id ?? 0) > 0 ? (int) $article->site_id : null,
            'article_id' => (int) $article->id,
            'prompt_id' => $promptId,
            'prompt_variables' => $variables,
            'editor_block_id' => $editorBlockId !== '' ? Str::limit($editorBlockId, 64, '') : null,
            'filename' => $slug.'.svg',
            'slug' => $slug,
            'path' => SeoMedia::placeholderLoadingPath(),
            'url' => SeoMedia::placeholderLoadingUrl(),
            'source' => $toolType === 'video' ? 'ai_video_prompt' : 'ai_prompt',
            'status' => 'processing',
            'error_message' => null,
        ]);
    }
}
