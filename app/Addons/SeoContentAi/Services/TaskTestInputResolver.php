<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Models\SeoProjectTask;
use App\Addons\SeoContentAi\Support\ArticlePostTypeResolver;
use App\Addons\SeoContentAi\Support\ProjectTaskOriginVariables;
use App\Addons\SeoContentAi\Support\TaskTestContext;
use App\Models\Site;
use Illuminate\Database\Eloquent\Builder;

final class TaskTestInputResolver
{
    /** @var null|callable(Builder): void */
    private $articleScope = null;

    public function __construct(
        private readonly SeoAnalyzerService $seoAnalyzer,
        private readonly SiteDomainPromptContextService $sitePromptContext,
        private readonly SeoMainDomainService $mainDomain,
        private readonly SeoPromptSettingsService $promptSettings,
        private readonly WorkflowParserService $workflowParser,
        private readonly WordPressArticleContentService $wordPressContent,
        private readonly ArticleGenerationInputResolver $articleGenerationInput,
    ) {}

    /**
     * @param  null|callable(Builder): void  $scopeArticles
     */
    public function resolve(?int $articleId, ?string $title, ?string $keyword, ?callable $scopeArticles = null): TaskTestContext
    {
        $this->articleScope = $scopeArticles;

        try {
            return $this->resolveScoped($articleId, $title, $keyword);
        } finally {
            $this->articleScope = null;
        }
    }

    public function resolveFromRawInput(string $input): TaskTestContext
    {
        $input = trim($input);
        if ($input === '') {
            throw new \InvalidArgumentException('Nhập nội dung {{input}} để chạy thử.');
        }

        $preview = mb_strlen($input) > 48 ? mb_substr($input, 0, 48).'…' : $input;

        return new TaskTestContext(
            article: null,
            isNewArticle: false,
            matchedBy: null,
            variables: [
                'input' => $input,
                'user_brief' => $input,
            ],
            summary: sprintf('Input test — «%s»', $preview),
        );
    }

    /**
     * @param  null|callable(Builder): void  $scopeArticles
     */
    public function resolveForProjectTask(SeoProjectTask $task, ?callable $scopeArticles = null): TaskTestContext
    {
        $type = SeoProjectTask::normalizeType($task->type);
        $promptInputs = SeoProjectTask::promptInputFields(
            isset($task->keyword) ? (string) $task->keyword : null,
            isset($task->title) ? (string) $task->title : null,
            isset($task->secondary_description) ? (string) $task->secondary_description : null,
        );
        $keyword = $promptInputs['keyword'];
        $title = $promptInputs['title'];
        $secondaryDescription = $promptInputs['secondary_description'];

        // Legacy fallback: single source_content trước khi tách keyword/title.
        if ($keyword === '' && $title === '' && SeoProjectTask::isNewArticleType($type)) {
            $legacy = trim((string) $task->source_content);
            $keyword = $legacy;
        }

        if (in_array($type, [SeoProjectTask::TYPE_CREATE, SeoProjectTask::TYPE_REWRITE], true)
            && $keyword === ''
            && $title === ''
        ) {
            throw new \InvalidArgumentException('Hạng mục dự án cần ít nhất Keyword hoặc Title.');
        }

        $galleryDescription = SeoProjectTask::isNewArticleType($type)
            && SeoProjectTask::normalizePostType($task->post_type) === SeoProjectTask::POST_TYPE_PRODUCT
            ? trim((string) ($task->description ?? ''))
            : '';
        $loaiSanPham = SeoProjectTask::isNewArticleType($type)
            && SeoProjectTask::normalizePostType($task->post_type) === SeoProjectTask::POST_TYPE_PRODUCT
            ? trim((string) ($task->loai_san_pham ?? ''))
            : '';

        if ($type === SeoProjectTask::TYPE_REWRITE || $type === SeoProjectTask::TYPE_IMPROVE) {
            return $this->stampProjectTaskOrigin(
                $this->withOptionalPromptInputs(
                    $this->withProductPromptVariables(
                        $this->resolveExistingArticleRewrite($task, $scopeArticles, $type),
                        $galleryDescription,
                        $loaiSanPham,
                    ),
                    $keyword,
                    $title,
                    $secondaryDescription,
                ),
                $task,
            );
        }

        $siteId = (int) ($task->site_id ?? 0);
        $postType = SeoProjectTask::normalizePostType($task->post_type);

        return $this->stampProjectTaskOrigin(
            $this->withOptionalPromptInputs(
                $this->withProductPromptVariables(
                    $this->applyProjectPostType(
                        $this->contextForNewArticleOnSite(
                            $title,
                            $keyword,
                            $siteId,
                            $postType,
                            $scopeArticles,
                            copyMissingTitleKeyword: false,
                        )->withProjectTaskType(SeoProjectTask::TYPE_CREATE),
                        $postType,
                    ),
                    $galleryDescription,
                    $loaiSanPham,
                ),
                $keyword,
                $title,
                $secondaryDescription,
            ),
            $task,
        );
    }

    private function withOptionalPromptInputs(
        TaskTestContext $context,
        string $keyword,
        string $title,
        string $secondaryDescription,
    ): TaskTestContext {
        $variables = $context->variables;

        if ($keyword !== '') {
            $variables['focus_keyword'] = $keyword;
            $variables['keyword'] = $keyword;
        } else {
            unset($variables['focus_keyword'], $variables['keyword']);
        }

        if ($title !== '') {
            $variables['post_title'] = $title;
            $variables['title'] = $title;
        } else {
            // Giữ post_title từ bài gốc (rewrite/improve); chỉ bỏ khi create không có title.
            if ($context->isNewArticle || ! isset($variables['post_title']) || trim((string) $variables['post_title']) === '') {
                unset($variables['post_title'], $variables['title']);
            }
        }

        if ($secondaryDescription !== '') {
            $variables['secondary_description'] = $secondaryDescription;
            $variables['description'] = $secondaryDescription;
        }

        return $context->withVariables($variables);
    }

    /**
     * @param  null|callable(Builder): void  $scopeArticles
     */
    private function resolveExistingArticleRewrite(
        SeoProjectTask $task,
        ?callable $scopeArticles,
        string $type,
    ): TaskTestContext {
        $this->articleScope = $scopeArticles;

        try {
            $article = null;
            $articleId = (int) ($task->article_id ?? 0);
            if ($articleId > 0) {
                $article = $this->articlesQuery()->find($articleId);
            }

            $pickerTitle = trim((string) $task->source_content);
            if (! $article instanceof SeoArticle && $pickerTitle !== '') {
                $article = $this->findArticleByTitle($pickerTitle);
            }

            if (! $article instanceof SeoArticle) {
                $label = $type === SeoProjectTask::TYPE_IMPROVE ? 'cải thiện' : 'viết lại';
                throw new \InvalidArgumentException(
                    'Không tìm thấy bài viết để '.$label.' (task #'.(int) $task->id.'). '
                    .'Hãy chọn đúng Target / Existing Article.',
                );
            }

            $article->loadMissing(['articleMetas', 'site']);
            $notes = trim((string) ($task->rewrite_notes ?? ''));
            $context = $this->contextFromArticle($article, 'id')
                ->withProjectTaskType($type)
                ->withRewriteOptions(SeoProjectTask::REWRITE_MODE_CONTENT, $notes !== '' ? $notes : null);

            $variables = $context->variables;

            if ($type === SeoProjectTask::TYPE_IMPROVE) {
                // Improve / editor-style: được dùng article body.
                $html = trim($this->wordPressContent->resolveEditorHtml($article));
                $markdown = $this->workflowParser->convertHtmlFragmentToMarkdown($html);
                if ($markdown === '') {
                    throw new \InvalidArgumentException(
                        $html === ''
                            ? 'Bài viết không có nội dung HTML để chuyển sang Markdown (kiểm tra body local, wp_post_content hoặc đồng bộ từ WordPress).'
                            : 'Bài viết không có nội dung Markdown sau khi chuyển đổi (có thể chỉ còn shortcode hoặc khối không có chữ).',
                    );
                }
                $variables['input'] = $markdown;
                $variables['post_content'] = $markdown;
                $variables['improve_instruction'] = $notes;
                $variables['rewrite_instruction'] = $notes;
                $variables['rewrite_notes'] = $notes;
            } else {
                // TYPE_REWRITE / «Viết lại nội dung»: raw outline artifact — cùng path first-run.
                $variables = $this->applyArticleGenerationSource($variables, $article);
                $variables['rewrite_instruction'] = $notes;
                $variables['rewrite_notes'] = $notes;
            }

            $taskSiteId = (int) ($task->site_id ?? 0);
            if ($context->siteId === null && $taskSiteId > 0) {
                $context = $context->withSiteId($taskSiteId);
            }

            return $context
                ->withVariables($variables)
                ->withRewriteOptions(SeoProjectTask::REWRITE_MODE_CONTENT, $notes !== '' ? $notes : null);
        } finally {
            $this->articleScope = null;
        }
    }

    private function stampProjectTaskOrigin(TaskTestContext $context, SeoProjectTask $task): TaskTestContext
    {
        return $context->withVariables(
            ProjectTaskOriginVariables::stamp(
                $context->variables,
                (int) $task->id,
            ),
        );
    }

    /**
     * Task.post_type là nguồn sự thật cho hạng mục viết bài mới.
     * Không để bài match nhầm (stale product/article) ghi đè context.
     */
    private function applyProjectPostType(TaskTestContext $context, string $postType): TaskTestContext
    {
        $normalized = SeoProjectTask::normalizePostType($postType);
        $variables = $context->variables;
        $promptVars = $this->promptSettings->promptVariables($normalized);
        $variables = array_merge($variables, $promptVars);
        $variables['_project_post_type'] = $normalized;

        $site = null;
        if ($context->article?->relationLoaded('site')) {
            $site = $context->article->site;
        } elseif ($context->siteId !== null && $context->siteId > 0) {
            $site = Site::query()->find($context->siteId);
        }

        $variables = array_merge(
            $variables,
            $this->sitePromptContext->promptVariablesForSite($site instanceof Site ? $site : null),
        );
        $variables['tone'] = $this->sitePromptContext->resolveToneForSite(
            $site instanceof Site ? $site : null,
            $promptVars['tone'] ?? ($variables['tone'] ?? ''),
        );

        return $context
            ->withVariables($variables)
            ->withPostType($normalized);
    }

    private function withProductPromptVariables(
        TaskTestContext $context,
        string $galleryDescription,
        string $loaiSanPham,
    ): TaskTestContext {
        $variables = $context->variables;
        $variables['gallery_description'] = $galleryDescription;
        $variables['loai_san_pham'] = $loaiSanPham;
        $variables['LOAI_SAN_PHAM'] = $loaiSanPham;

        return new TaskTestContext(
            article: $context->article,
            isNewArticle: $context->isNewArticle,
            matchedBy: $context->matchedBy,
            variables: $variables,
            summary: $context->summary,
            siteId: $context->siteId,
            postType: $context->postType,
            projectTaskType: $context->projectTaskType,
            rewriteMode: $context->rewriteMode,
            rewriteNotes: $context->rewriteNotes,
        );
    }

    private function resolveRewriteByContent(SeoProjectTask $task, ?callable $scopeArticles): TaskTestContext
    {
        $this->articleScope = $scopeArticles;

        try {
            $article = null;
            $articleId = (int) ($task->article_id ?? 0);
            if ($articleId > 0) {
                $article = $this->articlesQuery()->find($articleId);
            }

            $title = trim((string) $task->source_content);
            if (! $article instanceof SeoArticle && $title !== '') {
                $article = $this->findArticleByTitle($title);
            }

            if (! $article instanceof SeoArticle) {
                throw new \InvalidArgumentException('Không tìm thấy bài viết để viết lại theo nội dung.');
            }

            $article->loadMissing(['articleMetas', 'site']);
            $notes = trim((string) ($task->rewrite_notes ?? ''));
            $context = $this->contextFromArticle($article, 'id')
                ->withProjectTaskType(SeoProjectTask::TYPE_REWRITE)
                ->withRewriteOptions(SeoProjectTask::REWRITE_MODE_CONTENT, $notes !== '' ? $notes : null);

            // Legacy helper — cùng ArticleGenerationInputResolver như TYPE_REWRITE.
            $variables = $this->applyArticleGenerationSource($context->variables, $article);
            $variables['rewrite_instruction'] = $notes;
            $variables['rewrite_notes'] = $notes;

            $taskSiteId = (int) ($task->site_id ?? 0);
            if ($context->siteId === null && $taskSiteId > 0) {
                $context = $context->withSiteId($taskSiteId);
            }

            return $context
                ->withVariables($variables)
                ->withRewriteOptions(SeoProjectTask::REWRITE_MODE_CONTENT, $notes !== '' ? $notes : null);
        } finally {
            $this->articleScope = null;
        }
    }

    private function resolveScoped(?int $articleId, ?string $title, ?string $keyword): TaskTestContext
    {
        $title = $this->normalize($title);
        $keyword = $this->normalize($keyword);

        if ($articleId !== null && $articleId > 0) {
            $article = $this->articlesQuery()->find($articleId);
            if ($article === null) {
                throw new \InvalidArgumentException('Không tìm thấy bài viết với ID #'.$articleId.' trong danh sách của bạn.');
            }

            return $this->contextFromArticle($article, 'id');
        }

        if ($title !== '') {
            $byTitle = $this->findArticleByTitle($title);
            if ($byTitle !== null) {
                return $this->contextFromArticle($byTitle, 'title');
            }
        }

        if ($keyword !== '') {
            $byKeyword = $this->findArticleByKeyword($keyword);
            if ($byKeyword !== null) {
                return $this->contextFromArticle($byKeyword, 'keyword');
            }
        }

        return $this->contextForNewArticle($title, $keyword);
    }

    private function articlesQuery(): Builder
    {
        $query = SeoArticle::query();
        if ($this->articleScope !== null) {
            ($this->articleScope)($query);
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    private function applyArticleGenerationSource(array $variables, SeoArticle $article): array
    {
        $source = $this->articleGenerationInput->resolveForArticle($article);
        $variables['input'] = $source->rawArtifact;
        unset($variables['post_content'], $variables['existing_body'], $variables['article_content']);

        return array_merge($variables, $source->toDebugVariables(), [
            // BC keys cho initialState seed trong TaskWorkflowTestRunner.
            'outline_id' => $source->sourceRunItemId !== null
                ? 'run_item:'.$source->sourceRunItemId.':outline'
                : 'article:'.(int) $article->getKey().':'.ArticleOutlineResolver::META_KEY,
            'outline_version' => $source->artifactVersion,
            'outline_source' => $source->sourceType,
        ]);
    }

    private function contextFromArticle(SeoArticle $article, string $matchedBy): TaskTestContext
    {
        $article->loadMissing(['articleMetas']);

        $focusKeyword = $this->seoAnalyzer->resolveFocusKeywordForArticle($article) ?? '';
        $postTitle = trim((string) ($article->title ?? ''));

        $variables = $this->baseVariables($postTitle, $focusKeyword, (int) $article->id);
        $article->loadMissing('site');
        $postType = ArticlePostTypeResolver::resolve($article);
        $promptVars = $this->promptSettings->promptVariables($postType);
        $variables = array_merge(
            $variables,
            $promptVars,
            $this->sitePromptContext->promptVariablesForSite($article->site),
        );
        $variables['tone'] = $this->sitePromptContext->resolveToneForSite(
            $article->site,
            $promptVars['tone'] ?? '',
        );

        return new TaskTestContext(
            article: $article,
            isNewArticle: false,
            matchedBy: $matchedBy,
            variables: $variables,
            summary: sprintf(
                'Bài có sẵn #%d — khớp theo %s: «%s»',
                $article->id,
                $matchedBy === 'id' ? 'ID' : ($matchedBy === 'title' ? 'tiêu đề' : 'từ khóa'),
                $postTitle !== '' ? $postTitle : ($focusKeyword !== '' ? $focusKeyword : '—'),
            ),
            siteId: (int) ($article->site_id ?? 0) > 0 ? (int) $article->site_id : null,
            postType: $postType,
        );
    }

    private function contextForNewArticle(string $title, string $keyword): TaskTestContext
    {
        $mainSite = $this->mainDomain->resolveMainSite();
        $siteId = $mainSite instanceof Site ? (int) $mainSite->id : 0;

        return $this->contextForNewArticleOnSite($title, $keyword, $siteId, 'article', $this->articleScope);
    }

    /**
     * @param  null|callable(Builder): void  $scopeArticles
     */
    private function contextForNewArticleOnSite(
        string $title,
        string $keyword,
        int $siteId,
        string $postType,
        ?callable $scopeArticles = null,
        bool $copyMissingTitleKeyword = true,
    ): TaskTestContext {
        $previousScope = $this->articleScope;
        $this->articleScope = $scopeArticles;

        try {
            $postTitle = $title;
            $focusKeyword = $keyword;

            if ($copyMissingTitleKeyword) {
                if ($postTitle === '' && $focusKeyword !== '') {
                    $postTitle = $focusKeyword;
                }

                if ($focusKeyword === '' && $postTitle !== '') {
                    $focusKeyword = $postTitle;
                }
            }

            $normalizedPostType = SeoProjectTask::normalizePostType($postType);

            if ($postTitle !== '') {
                $byTitle = $this->findArticleByTitle($postTitle);
                if (
                    $byTitle instanceof SeoArticle
                    && $this->articleMatchesPostType($byTitle, $normalizedPostType)
                ) {
                    return $this->contextFromArticle($byTitle, 'title');
                }
            }

            if ($focusKeyword !== '') {
                $byKeyword = $this->findArticleByKeyword($focusKeyword);
                if (
                    $byKeyword instanceof SeoArticle
                    && $this->articleMatchesPostType($byKeyword, $normalizedPostType)
                ) {
                    return $this->contextFromArticle($byKeyword, 'keyword');
                }
            }

            $variables = $this->baseVariables($postTitle, $focusKeyword, null);
            $site = $siteId > 0 ? Site::query()->find($siteId) : $this->mainDomain->resolveMainSite();
            $promptVars = $this->promptSettings->promptVariables($normalizedPostType);
            $variables = array_merge(
                $variables,
                $promptVars,
                $this->sitePromptContext->promptVariablesForSite($site instanceof Site ? $site : null),
            );
            $variables['tone'] = $this->sitePromptContext->resolveToneForSite(
                $site instanceof Site ? $site : null,
                $promptVars['tone'] ?? '',
            );
            $variables['_project_post_type'] = $normalizedPostType;

            $label = $postTitle !== '' ? $postTitle : $focusKeyword;
            $summary = sprintf(
                'Tạo bài mới — «%s» (loại %s, site #%s).',
                $label,
                $normalizedPostType,
                $siteId > 0 ? (string) $siteId : '—',
            );

            return new TaskTestContext(
                article: null,
                isNewArticle: true,
                matchedBy: null,
                variables: $variables,
                summary: $summary,
                siteId: $siteId > 0 ? $siteId : null,
                postType: $normalizedPostType,
            );
        } finally {
            $this->articleScope = $previousScope;
        }
    }

    /**
     * @return array<string, string>
     */
    private function baseVariables(string $postTitle, string $focusKeyword, ?int $articleId): array
    {
        $variables = [
            'post_title' => $postTitle,
            'focus_keyword' => $focusKeyword,
        ];

        if ($articleId !== null) {
            $variables['article_id'] = (string) $articleId;
        }

        return $variables;
    }

    private function articleMatchesPostType(SeoArticle $article, string $postType): bool
    {
        return ArticlePostTypeResolver::resolve($article) === SeoProjectTask::normalizePostType($postType);
    }

    private function findArticleByTitle(string $title): ?SeoArticle
    {
        $exact = $this->articlesQuery()
            ->where('title', $title)
            ->orderByDesc('id')
            ->first();

        if ($exact instanceof SeoArticle) {
            return $exact;
        }

        return $this->articlesQuery()
            ->where('title', 'like', '%'.$this->escapeLike($title).'%')
            ->orderByDesc('id')
            ->first();
    }

    private function findArticleByKeyword(string $keyword): ?SeoArticle
    {
        $normalized = mb_strtolower($keyword);

        return $this->articlesQuery()
            ->whereHas('articleMetas', function (Builder $query) use ($normalized, $keyword): void {
                $query->where('meta_key', 'seo_focus_keyword')
                    ->where(function (Builder $inner) use ($normalized, $keyword): void {
                        $inner->whereRaw('LOWER(meta_value) = ?', [$normalized])
                            ->orWhere('meta_value', 'like', '%'.$this->escapeLike($keyword).'%');
                    });
            })
            ->orderByDesc('id')
            ->first();
    }

    private function normalize(?string $value): string
    {
        return trim((string) $value);
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['%', '_'], ['\\%', '\\_'], $value);
    }
}
