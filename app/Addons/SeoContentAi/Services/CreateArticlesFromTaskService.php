<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Automation\Data\ActionContext;
use App\Addons\SeoContentAi\Automation\Data\ActionResult;
use App\Addons\SeoContentAi\Automation\Migration\AutomationMigrationWriteException;
use App\Addons\SeoContentAi\Automation\Migration\ProjectArticleCreateCallerBridge;
use App\Addons\SeoContentAi\Automation\Runtime\ActionRunner;
use App\Addons\SeoContentAi\Automation\Support\ArticleCreateOriginResolver;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Models\SeoProjectTask;
use App\Addons\SeoContentAi\Models\SeoTask;
use App\Addons\SeoContentAi\Support\KeywordFocusAttach;
use App\Addons\SeoContentAi\Support\ProjectTaskOriginVariables;
use App\Addons\SeoContentAi\Support\SeoAccessControl;
use App\Addons\SeoContentAi\Support\TaskTestContext;
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
        private readonly ProjectArticleCreateCallerBridge $articleCreateBridge,
        private readonly ActionRunner $actionRunner,
        private readonly ArticleCreateOriginResolver $originResolver,
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
            throw new \InvalidArgumentException('Quy trình tạo bài viết (#'.$taskId.') không tồn tại.');
        }

        if (! $task->is_active) {
            throw new \InvalidArgumentException('Quy trình «'.$task->name.'» đang tắt. Hãy kích hoạt hoặc chọn task khác.');
        }

        $this->assertSiteAccessible($siteId);
        $this->syncDomainLinkListKeywords($siteId);

        $keywords = $this->parseKeywords($keywordsRaw);
        if ($keywords === []) {
            throw new \InvalidArgumentException('Nhập ít nhất một từ khóa.');
        }

        $scope = function (Builder $builder): void {
            if (! SeoAccessControl::shouldScopeToAccountOwner()) {
                return;
            }

            $builder->whereIn(
                'site_id',
                SeoAccessControl::accessibleSitesQuery()->select('id'),
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
                    $messages[] = '«'.$keyword.'»: quy trình có bước lỗi.';

                    continue;
                }

                $article = $this->resolveArticleFromWorkflow($context, $steps, $siteId, $keyword, $context->variables);
                $this->workflowRunner->applyParsedMetaFromSteps($article, $steps);
                $created++;
                $articleIds[] = (int) $article->id;
                $messages[] = '«'.$keyword.'»: đã tạo bài nháp và chạy quy trình.';
            } catch (\Throwable $exception) {
                $failed++;
                $messages[] = '«'.$keyword.'»: '.$exception->getMessage();
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
     * @return array{success: bool, article_id: ?int, message: string, steps: list<array<string, mixed>>}
     */
    public function runPublishWorkflowForContext(TaskTestContext $context, int $siteId): array
    {
        $isContentRewrite = $context->rewriteMode === SeoProjectTask::REWRITE_MODE_CONTENT
            || in_array((string) ($context->projectTaskType ?? ''), [
                SeoProjectTask::TYPE_REWRITE,
                SeoProjectTask::TYPE_IMPROVE,
            ], true);
        $taskId = $isContentRewrite
            ? ($this->settings->getRewriteArticleTaskId() ?? $this->settings->getPublishArticleTaskId())
            : $this->settings->getPublishArticleTaskId();

        if ($taskId === null) {
            throw new \InvalidArgumentException(
                $isContentRewrite
                    ? 'Chưa cấu hình quy trình Viết lại bài. Vào SEO → Cài đặt → Quy trình để chọn task.'
                    : 'Chưa cấu hình quy trình Đăng bài viết. Vào SEO → Tùy chỉnh để chọn task.',
            );
        }

        $task = SeoTask::query()->find($taskId);
        if ($task === null) {
            throw new \InvalidArgumentException('Quy trình tạo bài viết (#'.$taskId.') không tồn tại.');
        }

        if (! $task->is_active) {
            throw new \InvalidArgumentException('Quy trình «'.$task->name.'» đang tắt.');
        }

        $resolvedSiteId = (int) ($context->siteId ?? $siteId);
        $this->assertSiteAccessible($resolvedSiteId);
        $this->syncDomainLinkListKeywords($resolvedSiteId);

        if ($isContentRewrite) {
            // TYPE_REWRITE: variables.input = outline markdown — không dùng làm keyword.
            $keyword = trim((string) ($context->variables['focus_keyword'] ?? ''));
            if ($keyword === '') {
                $keyword = trim((string) ($context->variables['post_title'] ?? ''));
            }
            if ($keyword === '' && $context->article !== null) {
                $keyword = trim((string) ($context->article->title ?? ''));
            }
        } else {
            $keyword = trim((string) ($context->variables['focus_keyword'] ?? ''));
            if ($keyword === '') {
                $keyword = trim((string) ($context->variables['post_title'] ?? ''));
            }
        }

        if ($keyword === '' && ! ($isContentRewrite && $context->article !== null)) {
            return [
                'success' => false,
                'article_id' => null,
                'steps' => [],
                'message' => $isContentRewrite
                    ? 'Thiếu từ khóa / tiêu đề (hoặc bài viết nguồn).'
                    : 'Thiếu từ khóa / tiêu đề.',
            ];
        }

        if ($keyword === '') {
            $keyword = 'rewrite';
        }

        $steps = [];

        try {
            $steps = $this->workflowRunner->run($task, $context);
            $stepFailed = collect($steps)->contains(fn (array $step): bool => ($step['status'] ?? '') === 'failed');
            if ($stepFailed) {
                $failure = $this->summarizeWorkflowFailure($steps);
                $articleId = $this->resolveArticleIdFromSteps($context, $steps);

                return [
                    'success' => false,
                    'article_id' => $articleId,
                    'message' => $failure['message'],
                    'failed_step' => $failure['failed_step'],
                    'steps' => $steps,
                ];
            }

            $article = $this->resolveArticleFromWorkflow($context, $steps, $resolvedSiteId, $keyword, $context->variables);
            $this->workflowRunner->applyParsedMetaFromSteps($article, $steps);

            return [
                'success' => true,
                'article_id' => (int) $article->id,
                'steps' => $steps,
                'message' => 'Đã chạy quy trình và tạo/cập nhật bài.',
            ];
        } catch (\Throwable $exception) {
            return [
                'success' => false,
                'article_id' => $context->article?->id,
                'steps' => $steps,
                'message' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     */
    private function resolveArticleIdFromSteps(TaskTestContext $context, array $steps): ?int
    {
        foreach (array_reverse($steps) as $step) {
            $articleId = (int) ($step['article_id'] ?? 0);
            if ($articleId > 0) {
                return $articleId;
            }
        }

        return $context->article instanceof SeoArticle
            ? (int) $context->article->id
            : null;
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
                    $this->ensureArticlePostType($article, $context);

                    return $article;
                }
            }
        }

        if ($context->article instanceof SeoArticle) {
            $this->ensureArticlePostType($context->article, $context);

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

        $originId = ProjectTaskOriginVariables::read($variables);
        $originType = $originId !== null
            ? ArticleCreateOriginResolver::ORIGIN_SEO_PROJECT_TASK
            : null;

        $focusKeyword = trim((string) ($variables['focus_keyword'] ?? $keyword));
        $correlationId = Str::uuid()->toString();

        $input = [
            'site_id' => $siteId,
            'title' => $title,
            'keyword' => $keyword,
            'post_type' => $postType,
            'language' => 'vi',
            'origin_type' => $originType,
            'origin_id' => $originId,
            'focus_keyword' => $focusKeyword,
            'steps_count' => count($steps),
        ];

        $existingByOrigin = $this->originResolver->findExisting(
            $originType,
            $originId,
            $siteId,
            $postType,
        );

        try {
            $normalized = $this->articleCreateBridge->run(
                input: $input,
                legacyWrite: function () use (
                    $siteId,
                    $keyword,
                    $title,
                    $postType,
                    $focusKeyword,
                    $steps,
                    $originType,
                    $originId,
                    $existingByOrigin,
                ): array {
                    if (is_array($existingByOrigin)) {
                        return $existingByOrigin;
                    }

                    return $this->legacyCreateDraftArticle(
                        $siteId,
                        $keyword,
                        $title,
                        $postType,
                        $focusKeyword,
                        $steps,
                        $originType,
                        $originId,
                    );
                },
                actionWrite: function () use (
                    $siteId,
                    $keyword,
                    $title,
                    $postType,
                    $focusKeyword,
                    $steps,
                    $originType,
                    $originId,
                    $correlationId,
                ): ActionResult {
                    $result = $this->actionRunner->run(
                        'article.create',
                        ActionContext::fromArray([
                            'origin' => 'migration.project_article_create',
                            'actor_id' => auth()->id() !== null ? (int) auth()->id() : null,
                            'site_id' => $siteId,
                            'correlation_id' => $correlationId,
                        ]),
                        [
                            'site_id' => $siteId,
                            'title' => $title,
                            'keyword' => $keyword !== '' ? $keyword : $focusKeyword,
                            'post_type' => $postType,
                            'language' => 'vi',
                            'origin_type' => $originType,
                            'origin_id' => $originId,
                        ],
                    );

                    if (! $result->success) {
                        return $result;
                    }

                    $articleId = (int) ($result->output['article_id'] ?? 0);
                    $deduplicated = (bool) ($result->output['deduplicated'] ?? false);
                    if ($articleId > 0 && ! $deduplicated) {
                        $article = SeoArticle::query()->find($articleId);
                        if ($article instanceof SeoArticle) {
                            $this->stampCreateArticleTaskRunMeta($article, $keyword, $steps);
                            if ($focusKeyword !== '' && $focusKeyword !== $keyword) {
                                $article->articleMetas()->updateOrCreate(
                                    ['meta_key' => 'seo_focus_keyword'],
                                    ['meta_value' => $focusKeyword],
                                );
                            }
                        }
                    }

                    return $result;
                },
                existingByOrigin: $existingByOrigin,
                correlationId: $correlationId,
            );
        } catch (AutomationMigrationWriteException $exception) {
            throw new \InvalidArgumentException($exception->getMessage(), 0, $exception);
        }

        $articleId = (int) (is_array($normalized) ? ($normalized['article_id'] ?? 0) : 0);
        $article = SeoArticle::query()->find($articleId);
        if (! $article instanceof SeoArticle) {
            throw new \RuntimeException('Article create bridge returned invalid article_id.');
        }

        return $article;
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     * @return array{
     *   article_id: int,
     *   site_id: int,
     *   post_type: string,
     *   status: string,
     *   title: string,
     *   deduplicated: bool
     * }
     */
    private function legacyCreateDraftArticle(
        int $siteId,
        string $keyword,
        string $title,
        string $postType,
        string $focusKeyword,
        array $steps,
        ?string $originType,
        ?int $originId,
    ): array {
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
            ['meta_value' => $focusKeyword !== '' ? $focusKeyword : $keyword],
        );

        $this->stampCreateArticleTaskRunMeta($article, $keyword, $steps);

        $article->articleMetas()->updateOrCreate(
            ['meta_key' => 'wp_post_type'],
            ['meta_value' => match ($postType) {
                SeoProjectTask::POST_TYPE_PRODUCT => 'product',
                SeoProjectTask::POST_TYPE_PRODUCT_CATEGORY => 'product_cat',
                SeoProjectTask::POST_TYPE_CATEGORY => 'category',
                default => 'post',
            }],
        );

        if ($originType !== null && $originType !== '' && $originId !== null && $originId > 0) {
            $this->originResolver->persistOriginMeta($article, $originType, $originId);
            if ($originType === ArticleCreateOriginResolver::ORIGIN_SEO_PROJECT_TASK) {
                $this->originResolver->attachToProjectTaskIfNeeded($originId, (int) $article->id);
            }
        }

        return [
            'article_id' => (int) $article->id,
            'site_id' => $siteId,
            'post_type' => $postType,
            'status' => 'draft',
            'title' => $title,
            'deduplicated' => false,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     */
    private function stampCreateArticleTaskRunMeta(SeoArticle $article, string $keyword, array $steps): void
    {
        $article->articleMetas()->updateOrCreate(
            ['meta_key' => 'create_article_task_run'],
            ['meta_value' => json_encode([
                'keyword' => $keyword,
                'steps_count' => count($steps),
                'ran_at' => now()->toIso8601String(),
            ], JSON_UNESCAPED_UNICODE)],
        );
    }

    private function ensureArticlePostType(SeoArticle $article, TaskTestContext $context): void
    {
        // Rewrite: giữ nguyên type bài có sẵn — không ghi đè product/article.
        if ($context->projectTaskType === SeoProjectTask::TYPE_REWRITE) {
            return;
        }

        if ($context->postType === null || trim((string) $context->postType) === '') {
            return;
        }

        $postType = SeoProjectTask::normalizePostType($context->postType);
        if (SeoProjectTask::normalizePostType((string) ($article->type ?? '')) === $postType) {
            return;
        }

        $article->update(['type' => $postType]);
        $article->articleMetas()->updateOrCreate(
            ['meta_key' => 'wp_post_type'],
            ['meta_value' => match ($postType) {
                SeoProjectTask::POST_TYPE_PRODUCT => 'product',
                SeoProjectTask::POST_TYPE_PRODUCT_CATEGORY => 'product_cat',
                SeoProjectTask::POST_TYPE_CATEGORY => 'category',
                default => 'post',
            }],
        );
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
            trim($phrase),
        );
    }

    private function assertSiteAccessible(int $siteId): void
    {
        if (! SeoAccessControl::canAccessSite($siteId)) {
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
        $prefix = $labelParts !== [] ? implode(' — ', $labelParts).': ' : '';

        return [
            'message' => $prefix.($stepMessage !== '' ? $stepMessage : 'Quy trình có bước lỗi.'),
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

        if (SeoAccessControl::shouldScopeToAccountOwner()) {
            $query->where('user_id', SeoAccessControl::accountSiteOwnerId());
        }

        return $query->pluck('name', 'id')->all();
    }
}
