<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Models\SeoProjectTask;
use App\Addons\SeoContentAi\Support\ArticlePostTypeResolver;
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

    /**
     * @param  null|callable(Builder): void  $scopeArticles
     */
    public function resolveForProjectTask(SeoProjectTask $task, ?callable $scopeArticles = null): TaskTestContext
    {
        $keyword = trim((string) $task->source_content);
        if ($keyword === '') {
            throw new \InvalidArgumentException('Hạng mục dự án thiếu từ khóa / tiêu đề.');
        }

        $galleryDescription = $task->type === SeoProjectTask::TYPE_NEW_KEYWORD
            && SeoProjectTask::normalizePostType($task->post_type) === SeoProjectTask::POST_TYPE_PRODUCT
            ? trim((string) ($task->description ?? ''))
            : '';
        $loaiSanPham = $task->type === SeoProjectTask::TYPE_NEW_KEYWORD
            && SeoProjectTask::normalizePostType($task->post_type) === SeoProjectTask::POST_TYPE_PRODUCT
            ? trim((string) ($task->loai_san_pham ?? ''))
            : '';

        if ($task->type === SeoProjectTask::TYPE_REWRITE) {
            $rewriteMode = SeoProjectTask::normalizeRewriteMode($task->rewrite_mode ?? null);

            if ($rewriteMode === SeoProjectTask::REWRITE_MODE_CONTENT) {
                return $this->withProductPromptVariables(
                    $this->resolveRewriteByContent($task, $scopeArticles),
                    $galleryDescription,
                    $loaiSanPham,
                );
            }

            $taskSiteId = (int) ($task->site_id ?? 0);
            $rewriteContext = $this->resolve(null, $keyword, $keyword, $scopeArticles)
                ->withProjectTaskType($task->type);

            if ($rewriteContext->isNewArticle && $taskSiteId > 0) {
                $siteOnlyScope = static fn (Builder $builder): Builder => $builder->where('site_id', $taskSiteId);
                $siteScopedContext = $this->resolve(null, $keyword, $keyword, $siteOnlyScope)
                    ->withProjectTaskType($task->type);

                if (! $siteScopedContext->isNewArticle) {
                    $rewriteContext = $siteScopedContext;
                }
            }

            // Nếu không tìm thấy bài cũ (sẽ tạo bài mới), đảm bảo site đúng với task/project
            if ($rewriteContext->siteId === null && $taskSiteId > 0) {
                $rewriteContext = $rewriteContext->withSiteId($taskSiteId);
            }

            return $this->withProductPromptVariables($rewriteContext, $galleryDescription, $loaiSanPham);
        }

        $siteId = (int) ($task->site_id ?? 0);
        $postType = SeoProjectTask::normalizePostType($task->post_type);

        return $this->withProductPromptVariables(
            $this->contextForNewArticleOnSite($keyword, $keyword, $siteId, $postType, $scopeArticles)
                ->withProjectTaskType($task->type),
            $galleryDescription,
            $loaiSanPham,
        );
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
            $html = trim($this->wordPressContent->resolveEditorHtml($article));
            $markdown = $this->workflowParser->convertHtmlFragmentToMarkdown($html);
            if ($markdown === '') {
                throw new \InvalidArgumentException(
                    $html === ''
                        ? 'Bài viết không có nội dung HTML để chuyển sang Markdown (kiểm tra body local, wp_post_content hoặc đồng bộ từ WordPress).'
                        : 'Bài viết không có nội dung Markdown sau khi chuyển đổi (có thể chỉ còn shortcode hoặc khối không có chữ).',
                );
            }

            $notes = trim((string) ($task->rewrite_notes ?? ''));
            $context = $this->contextFromArticle($article, 'id')
                ->withProjectTaskType(SeoProjectTask::TYPE_REWRITE)
                ->withRewriteOptions(SeoProjectTask::REWRITE_MODE_CONTENT, $notes !== '' ? $notes : null);

            $variables = $context->variables;
            $variables['input'] = $markdown;
            $variables['post_content'] = $markdown;
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

    private function contextFromArticle(SeoArticle $article, string $matchedBy): TaskTestContext
    {
        $article->loadMissing(['keywords', 'articleMetas']);

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
    ): TaskTestContext {
        $previousScope = $this->articleScope;
        $this->articleScope = $scopeArticles;

        try {
            $postTitle = $title;
            $focusKeyword = $keyword;

            if ($postTitle === '' && $focusKeyword !== '') {
                $postTitle = $focusKeyword;
            }

            if ($focusKeyword === '' && $postTitle !== '') {
                $focusKeyword = $postTitle;
            }

            if ($postTitle !== '') {
                $byTitle = $this->findArticleByTitle($postTitle);
                if ($byTitle instanceof SeoArticle) {
                    return $this->contextFromArticle($byTitle, 'title');
                }
            }

            if ($focusKeyword !== '') {
                $byKeyword = $this->findArticleByKeyword($focusKeyword);
                if ($byKeyword instanceof SeoArticle) {
                    return $this->contextFromArticle($byKeyword, 'keyword');
                }
            }

            $variables = $this->baseVariables($postTitle, $focusKeyword, null);
            $site = $siteId > 0 ? Site::query()->find($siteId) : $this->mainDomain->resolveMainSite();
            $normalizedPostType = SeoProjectTask::normalizePostType($postType);
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

        $viaRelation = $this->articlesQuery()
            ->whereHas('keywords', function (Builder $query) use ($normalized, $keyword): void {
                $query->whereRaw('LOWER(phrase) = ?', [$normalized])
                    ->orWhere('phrase', 'like', '%'.$this->escapeLike($keyword).'%');
            })
            ->orderByDesc('id')
            ->first();

        if ($viaRelation instanceof SeoArticle) {
            return $viaRelation;
        }

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
