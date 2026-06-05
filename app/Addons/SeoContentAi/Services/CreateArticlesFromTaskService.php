<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Models\SeoProjectTask;
use App\Addons\SeoContentAi\Support\TaskTestContext;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Models\SeoTask;
use App\Addons\SeoContentAi\Support\KeywordFocusAttach;
use App\Models\Site;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

final class CreateArticlesFromTaskService
{
    public function __construct(
        private readonly SeoCreateArticleSettingsService $settings,
        private readonly TaskTestInputResolver $inputResolver,
        private readonly TaskWorkflowTestRunner $workflowRunner,
        private readonly SeoMainDomainService $mainDomain,
        private readonly DomainLinkListKeywordSyncService $linkListSync,
    ) {}

    /**
     * @return array{created: int, failed: int, messages: list<string>}
     */
    public function runFromKeywords(string $keywordsRaw): array
    {
        $siteId = $this->mainDomain->resolveMainSiteId();
        if ($siteId === null) {
            throw new \InvalidArgumentException(
                'Chưa có miền chính. Vào SEO → Danh sách tên miền → «Đặt làm chính» cho một domain.',
            );
        }

        return $this->runFromKeywordsForSite($keywordsRaw, $siteId);
    }

    /**
     * @return array{created: int, failed: int, messages: list<string>}
     */
    public function runFromKeywordsForSite(string $keywordsRaw, int $siteId): array
    {
        $taskId = $this->settings->getPublishArticleTaskId();
        if ($taskId === null) {
            throw new \InvalidArgumentException(
                'Chưa cấu hình quy trình Đăng bài viết. Vào SEO → Tùy chỉnh để chọn task.',
            );
        }

        $task = SeoTask::query()->find($taskId);
        if ($task === null) {
            throw new \InvalidArgumentException('Quy trình tạo bài viết (#' . $taskId . ') không tồn tại.');
        }

        if (! $task->is_active) {
            throw new \InvalidArgumentException('Quy trình «' . $task->name . '» đang tắt. Hãy kích hoạt hoặc chọn task khác.');
        }

        $this->assertSiteAccessible($siteId);
        $this->syncDomainLinkListKeywords($siteId);

        $keywords = $this->parseKeywords($keywordsRaw);
        if ($keywords === []) {
            throw new \InvalidArgumentException('Nhập ít nhất một từ khóa.');
        }

        $scope = function (Builder $builder): void {
            if (auth()->user()?->role === 'admin') {
                return;
            }

            $builder->whereIn(
                'site_id',
                Site::query()->where('user_id', auth()->id())->select('id'),
            );
        };

        $created = 0;
        $failed = 0;
        $messages = [];
        $articleIds = [];

        foreach ($keywords as $keyword) {
            try {
                $context = $this->inputResolver->resolve(null, $keyword, $keyword, $scope);
                $steps = $this->workflowRunner->run($task, $context);

                $stepFailed = collect($steps)->contains(fn (array $step): bool => ($step['status'] ?? '') === 'failed');
                if ($stepFailed) {
                    $failed++;
                    $messages[] = '«' . $keyword . '»: quy trình có bước lỗi.';

                    continue;
                }

                $article = $this->resolveArticleFromWorkflow($context, $steps, $siteId, $keyword, $context->variables);
                $this->workflowRunner->applyParsedMetaFromSteps($article, $steps);
                $created++;
                $articleIds[] = (int) $article->id;
                $messages[] = '«' . $keyword . '»: đã tạo bài nháp và chạy quy trình.';
            } catch (\Throwable $exception) {
                $failed++;
                $messages[] = '«' . $keyword . '»: ' . $exception->getMessage();
            }
        }

        return [
            'created' => $created,
            'failed' => $failed,
            'messages' => $messages,
            'article_ids' => $articleIds,
        ];
    }

    /**
     * Chạy quy trình Đăng bài viết (SEO → Tùy chỉnh) cho một từ khóa trên domain cụ thể.
     *
     * @return array{created: int, failed: int, messages: list<string>, article_ids: list<int>}
     */
    public function runFromSingleKeyword(string $keyword, int $siteId): array
    {
        return $this->runFromKeywordsForSite(trim($keyword), $siteId);
    }

    /**
     * @return array{success: bool, article_id: ?int, message: string}
     */
    public function runPublishWorkflowForContext(TaskTestContext $context, int $siteId): array
    {
        $taskId = $this->settings->getPublishArticleTaskId();
        if ($taskId === null) {
            throw new \InvalidArgumentException(
                'Chưa cấu hình quy trình Đăng bài viết. Vào SEO → Tùy chỉnh để chọn task.',
            );
        }

        $task = SeoTask::query()->find($taskId);
        if ($task === null) {
            throw new \InvalidArgumentException('Quy trình tạo bài viết (#' . $taskId . ') không tồn tại.');
        }

        if (! $task->is_active) {
            throw new \InvalidArgumentException('Quy trình «' . $task->name . '» đang tắt.');
        }

        $resolvedSiteId = (int) ($context->siteId ?? $siteId);
        $this->assertSiteAccessible($resolvedSiteId);
        $this->syncDomainLinkListKeywords($resolvedSiteId);

        $keyword = trim((string) ($context->variables['focus_keyword'] ?? $context->variables['post_title'] ?? ''));
        if ($keyword === '') {
            return [
                'success' => false,
                'article_id' => null,
                'message' => 'Thiếu từ khóa / tiêu đề.',
            ];
        }

        try {
            $steps = $this->workflowRunner->run($task, $context);
            $stepFailed = collect($steps)->contains(fn (array $step): bool => ($step['status'] ?? '') === 'failed');
            if ($stepFailed) {
                $failure = $this->summarizeWorkflowFailure($steps);

                return [
                    'success' => false,
                    'article_id' => $context->article?->id,
                    'message' => $failure['message'],
                    'failed_step' => $failure['failed_step'],
                ];
            }

            $article = $this->resolveArticleFromWorkflow($context, $steps, $resolvedSiteId, $keyword, $context->variables);
            $this->workflowRunner->applyParsedMetaFromSteps($article, $steps);

            return [
                'success' => true,
                'article_id' => (int) $article->id,
                'message' => 'Đã chạy quy trình và tạo/cập nhật bài.',
            ];
        } catch (\Throwable $exception) {
            return [
                'success' => false,
                'article_id' => $context->article?->id,
                'message' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @param  array<string, string>  $variables
     * @param  list<array<string, mixed>>  $steps
     */
    private function resolveArticleFromWorkflow(
        TaskTestContext $context,
        array $steps,
        int $siteId,
        string $keyword,
        array $variables,
    ): SeoArticle {
        foreach ($steps as $step) {
            if (($step['type'] ?? '') === 'action' && is_numeric($step['article_id'] ?? null)) {
                $article = SeoArticle::query()->find((int) $step['article_id']);
                if ($article instanceof SeoArticle) {
                    return $article;
                }
            }
        }

        if ($context->article instanceof SeoArticle) {
            return $context->article;
        }

        if ($context->postType !== null && $context->postType !== '') {
            $variables['_project_post_type'] = $context->postType;
        }

        return $this->createDraftArticle($siteId, $keyword, $variables, $steps);
    }

    /**
     * @param  array<string, string>  $variables
     * @param  list<array<string, mixed>>  $steps
     */
    private function createDraftArticle(int $siteId, string $keyword, array $variables, array $steps): SeoArticle
    {
        $title = trim((string) ($variables['post_title'] ?? ''));
        if ($title === '') {
            $title = $keyword;
        }

        $postType = SeoProjectTask::normalizePostType(
            (string) ($variables['_project_post_type'] ?? 'article'),
        );

        $slug = Str::slug($keyword);
        if ($slug === '') {
            $slug = Str::slug($title);
        }

        $article = SeoArticle::query()->create([
            'site_id' => $siteId,
            'user_id' => auth()->id(),
            'type' => $postType,
            'title' => $title,
            'slug' => $slug !== '' ? $slug : null,
            'status' => 'draft',
            'body' => '',
            'language' => 'vi',
        ]);

        $this->attachKeyword($article, $keyword);

        $article->articleMetas()->updateOrCreate(
            ['meta_key' => 'seo_focus_keyword'],
            ['meta_value' => trim((string) ($variables['focus_keyword'] ?? $keyword))],
        );

        $article->articleMetas()->updateOrCreate(
            ['meta_key' => 'create_article_task_run'],
            ['meta_value' => json_encode([
                'keyword' => $keyword,
                'steps_count' => count($steps),
                'ran_at' => now()->toIso8601String(),
            ], JSON_UNESCAPED_UNICODE)],
        );

        return $article;
    }

    private function attachKeyword(SeoArticle $article, string $phrase): void
    {
        $normalized = mb_strtolower(trim($phrase));
        if ($normalized === '') {
            return;
        }

        KeywordFocusAttach::attachMainKeyword(
            $article,
            (int) $article->site_id,
            (int) auth()->id(),
            trim($phrase),
        );
    }

    private function assertSiteAccessible(int $siteId): void
    {
        $query = Site::query()->whereKey($siteId);

        if (auth()->user()?->role !== 'admin') {
            $query->where('user_id', auth()->id());
        }

        if (! $query->exists()) {
            throw new \InvalidArgumentException('Website không hợp lệ hoặc bạn không có quyền.');
        }
    }

    private function syncDomainLinkListKeywords(int $siteId): void
    {
        $site = Site::query()->find($siteId);
        if ($site instanceof Site) {
            $this->linkListSync->syncFromStoredContext($site);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     * @return array{message: string, failed_step: ?array{title: string, prompt_name: string, message: string}}
     */
    private function summarizeWorkflowFailure(array $steps): array
    {
        $failed = collect($steps)
            ->first(fn (array $step): bool => (string) ($step['status'] ?? '') === 'failed');

        if (! is_array($failed)) {
            return [
                'message' => 'Quy trình có bước lỗi.',
                'failed_step' => null,
            ];
        }

        $stepMessage = trim((string) ($failed['message'] ?? ''));
        $stepTitle = trim((string) ($failed['title'] ?? ''));
        $promptName = trim((string) ($failed['prompt_name'] ?? ''));

        $labelParts = array_values(array_filter([$stepTitle, $promptName]));
        $prefix = $labelParts !== [] ? implode(' — ', $labelParts) . ': ' : '';

        return [
            'message' => $prefix . ($stepMessage !== '' ? $stepMessage : 'Quy trình có bước lỗi.'),
            'failed_step' => [
                'title' => $stepTitle,
                'prompt_name' => $promptName,
                'message' => $stepMessage,
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private function parseKeywords(string $raw): array
    {
        $parts = preg_split('/[\r\n,;]+/u', $raw) ?: [];
        $keywords = [];

        foreach ($parts as $part) {
            $phrase = trim($part);
            if ($phrase === '') {
                continue;
            }
            $keywords[] = $phrase;
        }

        return array_values(array_unique($keywords));
    }

    /**
     * @return array<int|string, string>
     */
    public function taskOptionsForSettings(): array
    {
        $query = SeoTask::query()->where('is_active', true)->orderBy('name');

        if (auth()->user()?->role !== 'admin') {
            $query->where('user_id', auth()->id());
        }

        return $query->pluck('name', 'id')->all();
    }
}
