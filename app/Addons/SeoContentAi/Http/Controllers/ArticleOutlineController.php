<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Http\Controllers;

use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Models\SeoArticleHeading;
use App\Addons\SeoContentAi\Services\ArticleHeadingAiGenerateService;
use App\Addons\SeoContentAi\Services\ArticleTocExtractionService;
use App\Addons\SeoContentAi\Services\HeadingDuplicateCheckerService;
use App\Addons\SeoContentAi\Services\HeadingDuplicateCheckService;
use App\Http\Controllers\Controller;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * API Outline (TOC) cho React Editor:
 * - GET    /api/seo/articles/{article}/outline
 * - POST   /api/seo/articles/{article}/outline
 * - PUT    /api/seo/articles/{article}/outline/{heading}
 * - POST   /api/seo/articles/{article}/outline/{heading}/generate
 */
class ArticleOutlineController extends Controller
{
    public function __construct(
        private readonly ArticleTocExtractionService $tocExtraction,
        private readonly HeadingDuplicateCheckService $duplicateCheck,
        private readonly HeadingDuplicateCheckerService $duplicateChecker,
        private readonly ArticleHeadingAiGenerateService $aiGenerate,
    ) {}

    public function index(SeoArticle $article): JsonResponse
    {
        abort_unless($this->canAccessArticle($article), 403);

        $headings = $article->headings()->get();

        // Bài cũ chưa từng bóc tách -> bóc tách lần đầu ngay khi mở tab Outline.
        if ($headings->isEmpty()) {
            $this->tocExtraction->extractForArticle($article);
            $headings = $article->headings()->get();
        }

        return response()->json([
            'success' => true,
            'article' => [
                'id' => (int) $article->id,
                'title' => (string) ($article->title ?? ''),
            ],
            'outline' => $this->buildTree($headings),
        ]);
    }

    /**
     * Dò trùng toàn bộ dàn ý hiện tại với các bài khác trong site.
     * Chỉ chạy khi user bấm nút "Dò trùng lặp" hoặc AI workflow gọi sau khi sinh outline.
     */
    public function checkDuplicates(SeoArticle $article): JsonResponse
    {
        abort_unless($this->canAccessArticle($article), 403);

        $headings = $article->headings()->get();

        $result = $this->duplicateChecker->check(
            $headings->mapWithKeys(
                fn (SeoArticleHeading $row): array => [(int) $row->id => [
                    'text' => (string) $row->heading_text,
                    'level' => (int) $row->level,
                ]],
            )->all(),
            (int) $article->site_id,
            (int) $article->id,
        );

        return response()->json([
            'success' => true,
            'has_duplicate' => $result['is_duplicate'],
            'duplicates' => $result['duplicates'],
        ]);
    }

    public function store(Request $request, SeoArticle $article): JsonResponse
    {
        abort_unless($this->canAccessArticle($article), 403);

        $validated = $request->validate([
            'heading_text' => ['required', 'string', 'max:255'],
            'level' => ['sometimes', 'integer', 'min:2', 'max:4'],
            'parent_id' => ['nullable', 'integer'],
        ]);

        $text = trim(preg_replace('/\s+/u', ' ', $validated['heading_text']) ?? $validated['heading_text']);
        if ($text === '') {
            return response()->json([
                'success' => false,
                'message' => 'Heading không được để trống.',
            ], 422);
        }

        $level = (int) ($validated['level'] ?? 2);
        $parentId = isset($validated['parent_id']) ? (int) $validated['parent_id'] : null;

        if ($parentId !== null) {
            $parent = SeoArticleHeading::query()
                ->where('article_id', $article->id)
                ->whereKey($parentId)
                ->first();

            if ($parent === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'Parent heading không hợp lệ.',
                ], 422);
            }
        }

        $maxSortOrder = (int) ($article->headings()->max('sort_order') ?? -1);

        $heading = SeoArticleHeading::query()->create([
            'article_id' => (int) $article->id,
            'heading_text' => $text,
            'heading_slug' => Str::slug($text),
            'level' => $level,
            'sort_order' => $maxSortOrder + 1,
            'parent_id' => $parentId,
        ]);

        return response()->json([
            'success' => true,
            'heading' => $this->headingToArray($heading),
        ], 201);
    }

    public function update(Request $request, SeoArticle $article, SeoArticleHeading $heading): JsonResponse
    {
        abort_unless($this->canAccessArticle($article), 403);
        abort_unless((int) $heading->article_id === (int) $article->id, 404);

        $validated = $request->validate([
            'heading_text' => ['required', 'string', 'max:255'],
        ]);

        $text = trim(preg_replace('/\s+/u', ' ', $validated['heading_text']) ?? $validated['heading_text']);
        if ($text === '') {
            return response()->json([
                'success' => false,
                'message' => 'Heading không được để trống.',
            ], 422);
        }

        $heading->update([
            'heading_text' => $text,
            'heading_slug' => Str::slug($text),
        ]);

        $duplicates = $this->duplicateCheck
            ->checkExactMatch($heading->heading_slug, (int) $article->site_id, (int) $article->id, (int) $heading->level)
            ->map(fn (SeoArticleHeading $row): array => [
                'heading_id' => (int) $row->id,
                'article_id' => (int) $row->article_id,
                'article_title' => (string) ($row->article?->title ?? ''),
                'heading_text' => (string) $row->heading_text,
            ])
            ->values();

        return response()->json([
            'success' => true,
            'heading' => $this->headingToArray($heading),
            'duplicates' => $duplicates,
        ]);
    }

    public function destroy(SeoArticle $article, SeoArticleHeading $heading): JsonResponse
    {
        abort_unless($this->canAccessArticle($article), 403);
        abort_unless((int) $heading->article_id === (int) $article->id, 404);

        $headingId = (int) $heading->id;
        $heading->delete();

        return response()->json([
            'success' => true,
            'heading_id' => $headingId,
        ]);
    }

    public function generate(SeoArticle $article, SeoArticleHeading $heading): JsonResponse
    {
        abort_unless($this->canAccessArticle($article), 403);
        abort_unless((int) $heading->article_id === (int) $article->id, 404);

        try {
            $text = $this->aiGenerate->generateHeadingText($article, $heading);
        } catch (RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        $heading->update([
            'heading_text' => $text,
            'heading_slug' => Str::slug($text),
        ]);

        return response()->json([
            'success' => true,
            'heading' => $this->headingToArray($heading),
        ]);
    }

    /**
     * @param  Collection<int, SeoArticleHeading>  $headings
     * @return list<array<string, mixed>>
     */
    private function buildTree(Collection $headings): array
    {
        $nodes = [];
        foreach ($headings as $heading) {
            $nodes[(int) $heading->id] = $this->headingToArray($heading) + ['children' => []];
        }

        $tree = [];
        foreach ($nodes as $id => &$node) {
            $parentId = $node['parent_id'];
            if ($parentId !== null && isset($nodes[$parentId])) {
                $nodes[$parentId]['children'][] = &$node;
            } else {
                $tree[] = &$node;
            }
        }
        unset($node);

        return $tree;
    }

    /**
     * @return array<string, mixed>
     */
    private function headingToArray(SeoArticleHeading $heading): array
    {
        return [
            'id' => (int) $heading->id,
            'article_id' => (int) $heading->article_id,
            'heading_text' => (string) $heading->heading_text,
            'heading_slug' => (string) $heading->heading_slug,
            'level' => (int) $heading->level,
            'sort_order' => (int) $heading->sort_order,
            'parent_id' => $heading->parent_id !== null ? (int) $heading->parent_id : null,
        ];
    }

    private function canAccessArticle(SeoArticle $article): bool
    {
        $user = auth()->user();
        if ($user === null) {
            return false;
        }

        if ($user->role === 'admin') {
            return true;
        }

        return Site::query()
            ->whereKey($article->site_id)
            ->where('user_id', $user->id)
            ->exists();
    }
}
