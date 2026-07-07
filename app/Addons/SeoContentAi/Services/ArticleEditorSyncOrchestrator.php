<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Jobs\AnalyzeArticleSeoJob;
use App\Addons\SeoContentAi\Jobs\SyncArticleBodyMediaToWordPressJob;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Support\ArticleEditorSaveContext;
use App\Addons\SeoContentAi\Support\SeoAccessControl;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

final class ArticleEditorSyncOrchestrator
{
    private const EDITOR_SYNC_OPTIONS = [
        'defer_inline_media_sync' => true,
        'defer_finalize_media' => true,
    ];

    public function __construct(
        private readonly ArticleEditorBundleApplyService $bundleApply,
        private readonly ArticleEditorPersistService $persist,
        private readonly WordPressArticleSyncService $syncService,
        private readonly WordPressArticleContentService $wpContent,
        private readonly SeoImageOptimizationService $imageOptimization,
    ) {}

    /**
     * @param  array<string, mixed>  $bundle
     * @return array{
     *     success: bool,
     *     message: string,
     *     steps?: list<array<string, mixed>>,
     *     warnings?: list<string>,
     *     reload?: bool,
     *     clear_local_state?: bool,
     *     media_sync_queued?: bool,
     *     notification?: array{title: string, body: string, status: string}
     * }
     */
    public function syncFromEditorBundle(SeoArticle $article, array $bundle): array
    {
        abort_if(SeoAccessControl::isContentManager(), 403);

        if (! SeoAccessControl::canSyncArticlesToWordPress()) {
            return $this->failureResponse('Vai trò Quản lý nội dung chỉ được lưu trên Laravel, không đồng bộ WordPress.');
        }

        $lock = Cache::lock('seo-wp-publish-article-'.(int) $article->id, 120);

        try {
            $lock->block(30);
        } catch (LockTimeoutException) {
            return $this->failureResponse('Hết thời gian chờ đồng bộ WordPress. Vui lòng thử lại.');
        }

        try {
            return $this->runSyncPipeline($article, $bundle);
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  array<string, mixed>  $bundle
     * @return array<string, mixed>
     */
    private function runSyncPipeline(SeoArticle $article, array $bundle): array
    {
        $steps = [];
        $warnings = [];
        $context = ArticleEditorSaveContext::fromBundle($article, $bundle);
        $this->bundleApply->apply($article, $bundle, $context);

        $html = (string) ($bundle['html'] ?? '');
        $seoAnalysis = is_array($bundle['seo_analysis'] ?? null) ? $bundle['seo_analysis'] : null;
        $seoOverride = $context->seoPayloadForWordPress();
        $article = $article->fresh() ?? $article;

        $skipSave = $this->syncService->shouldSkipSaveLocalPhase($article, $html, $seoAnalysis);
        if ($skipSave['skip']) {
            $steps[] = $this->step('save_local', 'done', 'Bỏ qua lưu local — nội dung chưa thay đổi.', skipped: true);
        } else {
            $html = $this->persist->persistLocalSilent($article, $context, $html);
            $this->syncService->storeLocalSaveFingerprint($article->fresh(), $html, $seoAnalysis);
            AnalyzeArticleSeoJob::dispatch(
                (int) $article->id,
                $html,
                $seoAnalysis,
                trim($context->title),
                $context->normalizedSlug() !== '' ? $context->normalizedSlug() : trim((string) ($article->slug ?? '')),
                $context->seoMetaDescription !== '' ? $context->seoMetaDescription : null,
            );
            $steps[] = $this->step('save_local', 'done', 'Đã lưu bản nháp Laravel.');
        }

        $article = $article->fresh() ?? $article;
        $ensure = $this->syncService->ensureWordPressPostForArticle($article, $seoOverride, self::EDITOR_SYNC_OPTIONS);
        if (! ($ensure['success'] ?? false)) {
            $steps[] = $this->step('prepare_payload', 'error', (string) ($ensure['message'] ?? 'Không liên kết được WordPress.'));

            return $this->failureResponse((string) ($ensure['message'] ?? 'Không liên kết được WordPress.'), $steps);
        }

        $wpContext = $this->syncService->resolveEditorSyncContext($article->fresh());
        if (! ($wpContext['success'] ?? false)) {
            $steps[] = $this->step('prepare_payload', 'error', (string) ($wpContext['message'] ?? 'Không lấy được context WordPress.'));

            return $this->failureResponse((string) ($wpContext['message'] ?? 'Không lấy được context WordPress.'), $steps);
        }

        $prepared = $this->syncService->prepareEditorSyncPayload($article->fresh(), $seoOverride, self::EDITOR_SYNC_OPTIONS);
        $mediaErrors = is_array($prepared['local_media_sync_errors'] ?? null)
            ? $prepared['local_media_sync_errors']
            : [];
        $warnings = array_merge($warnings, $mediaErrors);

        $prepareDetail = (string) ($ensure['step_detail'] ?? '');
        if ($prepared['skip_editor_sync'] ?? false) {
            $prepareDetail .= ($prepareDetail !== '' ? ', ' : '').'editor_sync_skip=1';
        }
        if ($prepared['defer_inline_media_sync'] ?? false) {
            $prepareDetail .= ($prepareDetail !== '' ? ', ' : '').'media_sync=queued';
        }

        $article->loadMissing('site');
        if ($article->site !== null) {
            $config = $this->imageOptimization->resolveForSite((int) $article->site->id);
            if ((bool) $config->auto_convert_webp && ! $this->imageOptimization->canEncodeWebp()) {
                $prepareDetail .= ($prepareDetail !== '' ? ', ' : '').'webp_encode=unavailable';
            }
        }

        $steps[] = $this->step(
            'prepare_payload',
            'done',
            $prepareDetail !== '' ? $prepareDetail : 'Đã chuẩn bị payload (ảnh nội dung xử lý nền).',
        );

        $syncResult = $this->syncService->executeEditorSyncRequest($article->fresh(), $wpContext, $prepared);
        if (! ($syncResult['success'] ?? false)) {
            $steps[] = $this->step(
                'editor_sync',
                'error',
                (string) ($syncResult['message'] ?? 'editor-sync thất bại.'),
            );

            return $this->failureResponse((string) ($syncResult['message'] ?? 'editor-sync thất bại.'), $steps);
        }

        $steps[] = $this->step(
            'editor_sync',
            'done',
            (string) ($syncResult['step_detail'] ?? ($syncResult['message'] ?? 'Đã gửi nội dung lên WordPress.')),
            skipped: (bool) ($syncResult['skipped'] ?? false),
        );

        $decoded = is_array($syncResult['decoded'] ?? null) ? $syncResult['decoded'] : [];
        $finalize = $this->syncService->completeEditorSyncResponse(
            $article->fresh(),
            $prepared,
            $decoded,
            self::EDITOR_SYNC_OPTIONS,
        );

        if (! ($finalize['success'] ?? false)) {
            $steps[] = $this->step('finalize', 'error', (string) ($finalize['message'] ?? 'Hoàn tất đồng bộ thất bại.'));

            return $this->failureResponse((string) ($finalize['message'] ?? 'Hoàn tất đồng bộ thất bại.'), $steps);
        }

        SyncArticleBodyMediaToWordPressJob::dispatch((int) $article->id, $seoOverride);

        $remoteIdentity = $this->wpContent->refreshSlugAndPermalinkFromWordPress($article->fresh());
        $syncBody = (string) ($finalize['message'] ?? 'Đã đồng bộ lên WordPress.');
        if (! ($remoteIdentity['success'] ?? false)) {
            $syncBody .= ' Chưa tải lại được slug/permalink mới nhất từ WordPress.';
        }
        $syncBody .= ' Ảnh trong nội dung đang được đồng bộ nền.';

        $steps[] = $this->step('finalize', 'done', (string) ($finalize['step_detail'] ?? $syncBody));

        return [
            'success' => true,
            'message' => $syncBody,
            'steps' => $steps,
            'warnings' => $warnings,
            'reload' => true,
            'clear_local_state' => true,
            'media_sync_queued' => true,
            'notification' => [
                'title' => 'WordPress synced',
                'body' => $syncBody,
                'status' => 'success',
            ],
        ];
    }

    /**
     * @return array{success: bool, message: string, steps?: list<array<string, mixed>>, notification?: array{title: string, body: string, status: string}}
     */
    private function failureResponse(string $message, array $steps = []): array
    {
        return [
            'success' => false,
            'message' => $message,
            'steps' => $steps,
            'notification' => [
                'title' => 'WordPress sync failed',
                'body' => $message,
                'status' => 'danger',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function step(string $id, string $status, string $detail, bool $skipped = false): array
    {
        return [
            'id' => $id,
            'status' => $status,
            'detail' => $detail,
            'skipped' => $skipped,
        ];
    }
}
