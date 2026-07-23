<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Enums\WpSyncJobStatus;
use App\Addons\SeoContentAi\Filament\Resources\ArticleResource;
use App\Addons\SeoContentAi\Http\Controllers\ArticleEditorSyncController;
use App\Addons\SeoContentAi\Jobs\ManualWordPressSyncJob;
use App\Addons\SeoContentAi\Services\ArticleWpSyncQueueService;
use App\Addons\SeoContentAi\Services\WordPress\WordPressManualSyncService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Goal 1–3 contracts: module payload normalize, exclusive assistant, Sync WP queue.
 * Remote-first: source / enum asserts only (no HTTP / no DB).
 */
final class ArticleEditorModuleSyncQueueHardeningTest extends TestCase
{
    private function js(string $relative): string
    {
        $path = dirname(__DIR__, 2).'/resources/js/'.$relative;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    private function methodBody(string $class, string $method): string
    {
        $ref = new ReflectionClass($class);
        $fn = $ref->getMethod($method);
        $start = (int) $fn->getStartLine();
        $end = (int) $fn->getEndLine();
        $lines = file((string) $ref->getFileName());
        self::assertNotFalse($lines);

        return implode('', array_slice($lines, $start - 1, $end - $start + 1));
    }

    public function test_normalize_module_payload_handles_null_and_envelopes(): void
    {
        $source = $this->js('utils/articleEditorPayloadAdapters.js');

        self::assertStringContainsString('export function unwrapModuleEnvelope', $source);
        self::assertStringContainsString('export function normalizeModulePayload', $source);
        self::assertStringContainsString("error: payload == null ? 'EMPTY_PAYLOAD'", $source);
        self::assertStringContainsString('cached: Boolean(payload.cached)', $source);
        self::assertStringContainsString('cached_at: payload.cached_at', $source);
        self::assertStringContainsString('normalizeModulePayload(responseOrPayload)', $source);
    }

    public function test_module_host_never_reads_cached_on_nullable_result(): void
    {
        $host = $this->js('components/ArticleEditorModuleHost.jsx');

        self::assertStringContainsString('EMPTY_FAQ_PAYLOAD', $host);
        self::assertStringContainsString('normalizeFaqPayload(data)', $host);
        self::assertStringContainsString("setFaqView({ status: 'ready', payload: normalized })", $host);
        self::assertStringNotContainsString('Boolean(normalized.cached)', $host);
        self::assertStringContainsString("faqView.status === 'loading'", $host);
        self::assertStringContainsString('logModuleLoadError', $host);
        self::assertStringContainsString('isAbortError', $host);
    }

    public function test_error_boundary_logs_module_context(): void
    {
        $source = $this->js('components/ArticleEditorModuleErrorBoundary.jsx');

        self::assertStringContainsString('logModuleLoadError', $source);
        self::assertStringContainsString('this.props.moduleName', $source);
        self::assertStringContainsString('this.props.articleId', $source);
        self::assertStringContainsString('this.props.endpoint', $source);
        self::assertStringContainsString('autoRetryUsed', $source);
        self::assertStringContainsString('reading', $source);
    }

    public function test_assistant_accordion_exclusive_by_default(): void
    {
        $navigator = $this->js('utils/seoAssistantNavigator.js');

        self::assertStringContainsString('panelFilterActive: true', $navigator);
        self::assertStringContainsString('closed: true', $navigator);
        self::assertStringContainsString('this.activePanel === panelId', $navigator);
        self::assertStringContainsString("source: 'discover'", $navigator);
        self::assertStringContainsString('this.selectChip(item.panelId)', $navigator);
    }

    public function test_unfinished_statuses_exclude_completed_and_cancelled(): void
    {
        $unfinished = WpSyncJobStatus::unfinishedValues();

        self::assertSame(
            [
                WpSyncJobStatus::Pending->value,
                WpSyncJobStatus::Processing->value,
                WpSyncJobStatus::Failed->value,
                WpSyncJobStatus::Stale->value,
            ],
            $unfinished,
        );
        self::assertNotContains(WpSyncJobStatus::Completed->value, $unfinished);
        self::assertNotContains(WpSyncJobStatus::Cancelled->value, $unfinished);
        self::assertSame($unfinished, ArticleWpSyncQueueService::unfinishedStatuses());
    }

    public function test_sync_queue_scope_uses_shared_unfinished_helper(): void
    {
        $scope = $this->methodBody(ArticleResource::class, 'applyWpSyncQueueScope');
        $listScope = $this->methodBody(ArticleResource::class, 'applyWpSyncQueueListScope');

        self::assertStringContainsString('applyUnfinishedMetaStatusConstraints', $scope);
        self::assertStringNotContainsString('STATUS_COMPLETED', $scope);
        self::assertStringContainsString('applyWpSyncQueueScope', $listScope);
    }

    public function test_manual_sync_job_is_unique_and_dispatched_after_commit(): void
    {
        $job = (string) file_get_contents(
            (new ReflectionClass(ManualWordPressSyncJob::class))->getFileName(),
        );
        $service = (string) file_get_contents(
            (new ReflectionClass(WordPressManualSyncService::class))->getFileName(),
        );

        self::assertStringContainsString('ShouldBeUnique', $job);
        self::assertStringContainsString('function uniqueId', $job);
        self::assertStringContainsString('manual-wp-sync:', $job);
        self::assertStringContainsString('->afterCommit()', $service);
        self::assertStringContainsString("'already_queued' => false", $service);
        self::assertStringContainsString("'already_queued' => true", $service);
    }

    public function test_sync_controller_sets_close_editor_on_success(): void
    {
        $body = $this->methodBody(ArticleEditorSyncController::class, 'syncWp');

        self::assertStringContainsString("'close_editor' => true", $body);
        self::assertStringContainsString('manual_sync_already_queued', $body);
        self::assertStringContainsString("'data' => null", $body);
    }

    public function test_frontend_closes_or_redirects_after_enqueue(): void
    {
        $api = $this->js('utils/articleEditorApi.js');

        self::assertStringContainsString('export function resolveSyncQueueListUrl', $api);
        self::assertStringContainsString('tab=queue', $api);
        self::assertStringContainsString('closeEditorTabOrRedirectToSyncQueue', $api);
        self::assertStringContainsString('prepareEditorExitAfterSyncEnqueue', $api);
        self::assertStringContainsString('window.close()', $api);
        self::assertStringNotContainsString('__seoArticleOperationTracker?.poll?.(articleId)', $api);
    }

    public function test_list_articles_exposes_sync_queue_badge_count(): void
    {
        $page = dirname(__DIR__, 2).'/Filament/Resources/ArticleResource/Pages/ListArticles.php';
        $blade = dirname(__DIR__, 2).'/resources/views/filament/resources/article-resource/pages/list-articles.blade.php';
        $pageSource = (string) file_get_contents($page);
        $bladeSource = (string) file_get_contents($blade);

        self::assertStringContainsString('function getSyncQueueBadgeCount', $pageSource);
        self::assertStringContainsString('applyContentTabScope($query, self::TAB_QUEUE)', $pageSource);
        self::assertStringContainsString('getSyncQueueBadgeCount()', $bladeSource);
        self::assertStringContainsString('wire:poll.15s', $bladeSource);
    }
}
