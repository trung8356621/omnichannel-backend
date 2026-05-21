<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Services\SeoAnalyzerService;
use App\Addons\SeoContentAi\Services\SeoMainDomainService;
use App\Addons\SeoContentAi\Services\SiteDomainPromptContextService;
use App\Addons\SeoContentAi\Support\TaskTestContext;
use Illuminate\Database\Eloquent\Builder;

final class TaskTestInputResolver
{
    /** @var null|callable(Builder): void */
    private $articleScope = null;

    public function __construct(
        private readonly SeoAnalyzerService $seoAnalyzer,
        private readonly SiteDomainPromptContextService $sitePromptContext,
        private readonly SeoMainDomainService $mainDomain,
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

    private function resolveScoped(?int $articleId, ?string $title, ?string $keyword): TaskTestContext
    {
        $title = $this->normalize($title);
        $keyword = $this->normalize($keyword);

        if ($articleId !== null && $articleId > 0) {
            $article = $this->articlesQuery()->find($articleId);
            if ($article === null) {
                throw new \InvalidArgumentException('Không tìm thấy bài viết với ID #' . $articleId . ' trong danh sách của bạn.');
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
        $variables = array_merge($variables, $this->sitePromptContext->promptVariablesForSite($article->site));

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
        $postTitle = $title;
        $focusKeyword = $keyword;

        if ($postTitle === '' && $focusKeyword !== '') {
            $postTitle = $focusKeyword;
        }

        if ($focusKeyword === '' && $postTitle !== '') {
            $focusKeyword = $postTitle;
        }

        $variables = $this->baseVariables($postTitle, $focusKeyword, null);
        $variables = array_merge($variables, $this->sitePromptContext->promptVariablesForSite($this->mainDomain->resolveMainSite()));

        if ($title === '' && $keyword === '') {
            throw new \InvalidArgumentException('Chọn bài viết hoặc nhập tiêu đề / từ khóa để chạy thử.');
        }

        $label = $title !== '' ? $title : $keyword;
        $summary = $title === $keyword || $keyword === ''
            ? sprintf('Tạo bài mới — «%s» (đã tìm theo tiêu đề rồi từ khóa, chưa có bài khớp).', $label)
            : 'Tạo bài mới (chưa có trong kho) — tiêu đề «' . $title . '», từ khóa «' . $keyword . '».';

        return new TaskTestContext(
            article: null,
            isNewArticle: true,
            matchedBy: null,
            variables: $variables,
            summary: $summary,
        );
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
            ->where('title', 'like', '%' . $this->escapeLike($title) . '%')
            ->orderByDesc('id')
            ->first();
    }

    private function findArticleByKeyword(string $keyword): ?SeoArticle
    {
        $normalized = mb_strtolower($keyword);

        $viaRelation = $this->articlesQuery()
            ->whereHas('keywords', function (Builder $query) use ($normalized, $keyword): void {
                $query->whereRaw('LOWER(phrase) = ?', [$normalized])
                    ->orWhere('phrase', 'like', '%' . $this->escapeLike($keyword) . '%');
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
                            ->orWhere('meta_value', 'like', '%' . $this->escapeLike($keyword) . '%');
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
