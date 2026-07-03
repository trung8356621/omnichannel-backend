<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Models\Keyword;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Support\DomainSyncManifestComparator;
use App\Addons\SeoContentAi\Support\KeywordFocusAttach;
use App\Addons\SeoContentAi\Support\RankMathSeoValueNormalizer;
use App\Addons\SeoContentAi\Support\WordPressPermalinkBuilder;
use App\Models\Site;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncDomainContentService
{
    private const INCREMENTAL_FETCH_CHUNK = 40;

    public function __construct(
        private readonly WordPressArticleTimestampService $timestampService,
        private readonly ArticleTocExtractionService $tocExtraction,
        private readonly DomainSyncManifestComparator $manifestComparator,
    ) {}

    /**
     * @param  array{is_test?:bool,limit_per_type?:int}  $options
     * @return array{success:bool,message:string,synced?:array<string,int>,counts?:array<string,int>}
     */
    public function sync(Site $site, array $options = []): array
    {
        $site->loadMissing('metas');

        $platform = (string) ($site->getMeta('seo_platform') ?? '');
        if ($platform !== 'wordpress') {
            return [
                'success' => false,
                'message' => 'Site chưa cấu hình nền tảng WordPress.',
            ];
        }

        $readToken = trim((string) ($site->getMeta('seo_read_token') ?? ''));
        if ($readToken === '') {
            return [
                'success' => false,
                'message' => 'Thiếu SEO Read Token. Hãy lưu token trong trang chỉnh sửa domain.',
            ];
        }

        $isTest = (bool) ($options['is_test'] ?? false);
        $limitPerType = (int) ($options['limit_per_type'] ?? 0);
        if ($isTest && $limitPerType <= 0) {
            $limitPerType = 2;
        }

        $url = $this->buildSyncUrl($site);

        try {
            $siteInfoResult = app(WordPressSiteInfoService::class)->fetchAndStore($site);
            if (! ($siteInfoResult['success'] ?? false)) {
                return [
                    'success' => false,
                    'message' => 'Không lấy được thông tin plugin SEO từ WordPress: '
                        .(string) ($siteInfoResult['message'] ?? 'Lỗi không xác định.'),
                ];
            }

            $response = Http::timeout(120)
                ->acceptJson()
                ->withToken($readToken)
                ->get($url, [
                    'is_test' => $isTest ? 1 : 0,
                    'limit_per_type' => $limitPerType,
                ]);

            if (! $response->successful()) {
                $message = (string) ($response->json('message') ?? $response->body());

                return [
                    'success' => false,
                    'message' => 'WordPress trả lỗi HTTP '.$response->status().': '.mb_substr($message, 0, 300),
                ];
            }

            $payload = $response->json();
            if (! is_array($payload) || ! ($payload['success'] ?? false)) {
                return [
                    'success' => false,
                    'message' => 'Phản hồi WordPress không hợp lệ.',
                ];
            }

            $items = $payload['items'] ?? [];
            if (! is_array($items)) {
                $items = [];
            }

            $synced = $this->importItems($site, $items);
            $counts = is_array($payload['counts'] ?? null) ? $payload['counts'] : [];

            $response = [
                'success' => true,
                'message' => sprintf(
                    'Đồng bộ thành công %d mục từ WordPress%s. Plugin SEO: %s.',
                    array_sum($synced),
                    $isTest ? ' (chế độ test)' : '',
                    (string) ($siteInfoResult['site_info']['active'] ?? 'none'),
                ),
                'synced' => $synced,
            ];

            if ($counts !== []) {
                $response['counts'] = $counts;
            }

            return $response;
        } catch (Throwable $e) {
            Log::error('SeoContentAi sync failed', [
                'site_id' => $site->id,
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Không kết nối được WordPress: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Lập kế hoạch đồng bộ bổ sung (manifest + so sánh local).
     *
     * @return array{
     *     success: bool,
     *     message: string,
     *     refs?: array<int, array<string, mixed>>,
     *     skipped?: int,
     *     new_count?: int,
     *     update_count?: int,
     *     total?: int
     * }
     */
    public function prepareIncrementalSync(Site $site): array
    {
        $validation = $this->validateWordPressSite($site);
        if ($validation !== null) {
            return $validation;
        }

        $readToken = trim((string) ($site->getMeta('seo_read_token') ?? ''));
        $manifestUrl = $this->buildSyncManifestUrl($site);

        try {
            $siteInfoResult = app(WordPressSiteInfoService::class)->fetchAndStore($site);
            if (! ($siteInfoResult['success'] ?? false)) {
                return [
                    'success' => false,
                    'message' => 'Không lấy được thông tin plugin SEO từ WordPress: '
                        .(string) ($siteInfoResult['message'] ?? 'Lỗi không xác định.'),
                ];
            }

            $manifestResponse = Http::timeout(120)
                ->acceptJson()
                ->withToken($readToken)
                ->get($manifestUrl);

            if ($manifestResponse->status() === 404) {
                return [
                    'success' => false,
                    'message' => 'Plugin WordPress chưa hỗ trợ đồng bộ bổ sung (cần TVH SEO AI Bridge ≥ 1.0.41). '
                        .'Hãy cập nhật plugin hoặc dùng «Làm sạch & Đồng bộ lại».',
                ];
            }

            if (! $manifestResponse->successful()) {
                $message = (string) ($manifestResponse->json('message') ?? $manifestResponse->body());

                return [
                    'success' => false,
                    'message' => 'WordPress trả lỗi HTTP '.$manifestResponse->status().': '.mb_substr($message, 0, 300),
                ];
            }

            $manifestPayload = $manifestResponse->json();
            if (! is_array($manifestPayload) || ! ($manifestPayload['success'] ?? false)) {
                return [
                    'success' => false,
                    'message' => 'Phản hồi manifest WordPress không hợp lệ.',
                ];
            }

            $entries = is_array($manifestPayload['entries'] ?? null) ? $manifestPayload['entries'] : [];
            $manifestCounts = is_array($manifestPayload['counts'] ?? null) ? $manifestPayload['counts'] : [];
            $manifestTotals = is_array($manifestPayload['totals'] ?? null) ? $manifestPayload['totals'] : [];
            $this->persistManifestCounts($site, $manifestCounts, $manifestTotals);

            $localArticles = SeoArticle::query()
                ->where('site_id', $site->id)
                ->where('wp_post_id', '>', 0)
                ->get(['wp_post_id', 'type', 'updated_at']);

            $plan = $this->manifestComparator->resolveFetchRefs($entries, $localArticles);
            $refs = $plan['refs'];
            $manifestTotal = count($entries);
            $localArticleCount = $localArticles
                ->filter(static fn (object $article): bool => in_array((string) ($article->type ?? 'article'), ['article', ''], true))
                ->count();
            $accounted = $plan['skipped'] + count($refs);

            if ($accounted < $manifestTotal) {
                Log::warning('SeoContentAi incremental sync manifest unaccounted entries', [
                    'site_id' => $site->id,
                    'manifest_entries' => $manifestTotal,
                    'accounted' => $accounted,
                    'unaccounted' => $manifestTotal - $accounted,
                ]);
            }

            Log::info('SeoContentAi incremental sync plan', [
                'site_id' => $site->id,
                'manifest_entries' => $manifestTotal,
                'manifest_counts' => $manifestCounts,
                'to_fetch' => count($refs),
                'new_count' => $plan['new_count'],
                'update_count' => $plan['update_count'],
                'skipped' => $plan['skipped'],
            ]);

            if ($refs === []) {
                $gapMessage = $this->buildManifestGapMessage($manifestCounts, $localArticleCount);

                return [
                    'success' => true,
                    'message' => $gapMessage !== ''
                        ? $gapMessage
                        : 'Không có thay đổi mới trên WordPress. Đã bỏ qua '.$plan['skipped'].' mục.',
                    'refs' => [],
                    'skipped' => $plan['skipped'],
                    'new_count' => 0,
                    'update_count' => 0,
                    'total' => 0,
                    'manifest_total' => $manifestTotal,
                    'manifest_counts' => $manifestCounts,
                ];
            }

            return [
                'success' => true,
                'message' => sprintf(
                    'Sẽ đồng bộ %d mục (%d mới, %d cập nhật, %d bỏ qua).',
                    count($refs),
                    $plan['new_count'],
                    $plan['update_count'],
                    $plan['skipped'],
                ),
                'refs' => $refs,
                'skipped' => $plan['skipped'],
                'new_count' => $plan['new_count'],
                'update_count' => $plan['update_count'],
                'total' => count($refs),
                'manifest_total' => $manifestTotal,
                'manifest_counts' => $manifestCounts,
            ];
        } catch (Throwable $e) {
            Log::error('SeoContentAi incremental sync prepare failed', [
                'site_id' => $site->id,
                'url' => $manifestUrl,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Không kết nối được WordPress: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Xử lý một lô refs (10–20 bài) — gọi từ Livewire nhiều lần.
     *
     * @param  array<int, array<string, mixed>>  $chunkRefs
     * @param  array{full_sync_items?: array<int, array<string, mixed>>|null}  $state
     * @return array{
     *     success: bool,
     *     message: string,
     *     synced?: array<string, int>,
     *     imported?: int,
     *     state?: array{full_sync_items?: array<int, array<string, mixed>>|null}
     * }
     */
    public function processIncrementalChunk(Site $site, array $chunkRefs, array $state = []): array
    {
        if ($chunkRefs === []) {
            return [
                'success' => true,
                'message' => 'Không có mục trong lô này.',
                'synced' => $this->emptySyncedCounts(),
                'imported' => 0,
                'state' => $state,
            ];
        }

        $readToken = trim((string) ($site->getMeta('seo_read_token') ?? ''));
        if ($readToken === '') {
            return [
                'success' => false,
                'message' => 'Thiếu SEO Read Token.',
            ];
        }

        try {
            $items = $this->fetchItemsByRefs($site, $readToken, $chunkRefs);

            if ($items === null) {
                $fullItems = $state['full_sync_items'] ?? null;
                if (! is_array($fullItems)) {
                    $fullItems = $this->fetchFullSyncItems($site, $readToken);
                    if ($fullItems === null) {
                        return [
                            'success' => false,
                            'message' => 'Không tải được nội dung từ WordPress.',
                        ];
                    }

                    $state['full_sync_items'] = $fullItems;
                }

                $items = $this->filterItemsByRefs($fullItems, $chunkRefs);
            }

            if ($items === []) {
                return [
                    'success' => false,
                    'message' => sprintf(
                        'WordPress không trả dữ liệu cho %d mục trong lô — sẽ thử lại khi tiếp tục đồng bộ.',
                        count($chunkRefs),
                    ),
                    'synced' => $this->emptySyncedCounts(),
                    'imported' => 0,
                    'state' => $state,
                ];
            }

            $synced = $this->importItems($site, $items);

            return [
                'success' => true,
                'message' => sprintf('Đã xử lý %d mục trong lô.', count($items)),
                'synced' => $synced,
                'imported' => count($items),
                'state' => $state,
            ];
        } catch (Throwable $e) {
            Log::error('SeoContentAi incremental sync chunk failed', [
                'site_id' => $site->id,
                'chunk_size' => count($chunkRefs),
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Lỗi khi xử lý lô đồng bộ: '.$e->getMessage(),
            ];
        }
    }

    public function incrementalSyncChunkSize(): int
    {
        $size = (int) config('seo-content-ai.incremental_sync_chunk_size', 15);

        return max(5, min(50, $size));
    }

    /**
     * @param  array<string, int>  $base
     * @param  array<string, int>  $add
     * @return array<string, int>
     */
    public function mergeSyncedCounts(array $base, array $add): array
    {
        foreach ($add as $key => $count) {
            $base[$key] = (int) ($base[$key] ?? 0) + (int) $count;
        }

        return $base;
    }

    /**
     * @deprecated Dùng prepareIncrementalSync + IncrementalDomainSyncRunner qua queue job.
     *
     * @return array{
     *     success: bool,
     *     message: string,
     *     synced?: array<string, int>,
     *     skipped?: int,
     *     new_count?: int,
     *     update_count?: int
     * }
     */
    public function syncIncremental(Site $site): array
    {
        $prepared = $this->prepareIncrementalSync($site);
        if (! ($prepared['success'] ?? false)) {
            return $prepared;
        }

        $refs = is_array($prepared['refs'] ?? null) ? $prepared['refs'] : [];
        if ($refs === []) {
            return [
                'success' => true,
                'message' => (string) ($prepared['message'] ?? ''),
                'synced' => $this->emptySyncedCounts(),
                'skipped' => (int) ($prepared['skipped'] ?? 0),
                'new_count' => (int) ($prepared['new_count'] ?? 0),
                'update_count' => (int) ($prepared['update_count'] ?? 0),
            ];
        }

        $accumulated = $this->emptySyncedCounts();
        $state = [];

        foreach (array_chunk($refs, $this->incrementalSyncChunkSize()) as $chunkRefs) {
            $chunk = $this->processIncrementalChunk($site, $chunkRefs, $state);
            if (! ($chunk['success'] ?? false)) {
                return $chunk;
            }

            $state = is_array($chunk['state'] ?? null) ? $chunk['state'] : $state;
            $accumulated = $this->mergeSyncedCounts(
                $accumulated,
                is_array($chunk['synced'] ?? null) ? $chunk['synced'] : [],
            );
        }

        return [
            'success' => true,
            'message' => sprintf(
                'Đồng bộ bổ sung xong: %d mục mới, %d cập nhật, %d bỏ qua.',
                (int) ($prepared['new_count'] ?? 0),
                (int) ($prepared['update_count'] ?? 0),
                (int) ($prepared['skipped'] ?? 0),
            ),
            'synced' => $accumulated,
            'skipped' => (int) ($prepared['skipped'] ?? 0),
            'new_count' => (int) ($prepared['new_count'] ?? 0),
            'update_count' => (int) ($prepared['update_count'] ?? 0),
        ];
    }

    /**
     * Xóa toàn bộ nội dung local của domain rồi đồng bộ full từ WordPress.
     *
     * @return array{success:bool,message:string,synced?:array<string,int>,deleted?:int}
     */
    public function resetAndFullSync(Site $site): array
    {
        $validation = $this->validateWordPressSite($site);
        if ($validation !== null) {
            return $validation;
        }

        $clearResult = app(ClearDomainArticlesService::class)->clear($site);
        if (! ($clearResult['success'] ?? false)) {
            return [
                'success' => false,
                'message' => (string) ($clearResult['message'] ?? 'Không dọn dẹp được dữ liệu local.'),
            ];
        }

        $syncResult = $this->sync($site, ['is_test' => false, 'limit_per_type' => 0]);
        if (! ($syncResult['success'] ?? false)) {
            return $syncResult;
        }

        return [
            'success' => true,
            'message' => sprintf(
                'Đã xóa %d bản ghi local và tải lại từ WordPress. %s',
                (int) ($clearResult['deleted'] ?? 0),
                (string) ($syncResult['message'] ?? ''),
            ),
            'synced' => is_array($syncResult['synced'] ?? null) ? $syncResult['synced'] : [],
            'deleted' => (int) ($clearResult['deleted'] ?? 0),
        ];
    }

    /**
     * @return array{success:bool,message:string}|null
     */
    private function validateWordPressSite(Site $site): ?array
    {
        $site->loadMissing('metas');

        $platform = (string) ($site->getMeta('seo_platform') ?? '');
        if ($platform !== 'wordpress') {
            return [
                'success' => false,
                'message' => 'Site chưa cấu hình nền tảng WordPress.',
            ];
        }

        $readToken = trim((string) ($site->getMeta('seo_read_token') ?? ''));
        if ($readToken === '') {
            return [
                'success' => false,
                'message' => 'Thiếu SEO Read Token. Hãy lưu token trong trang chỉnh sửa domain.',
            ];
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $refs
     * @return array<int, array<string, mixed>>|null
     */
    private function fetchItemsByRefs(Site $site, string $readToken, array $refs): ?array
    {
        $itemsUrl = $this->buildSyncItemsUrl($site);
        $items = [];

        foreach (array_chunk($refs, self::INCREMENTAL_FETCH_CHUNK) as $chunk) {
            $response = Http::timeout(120)
                ->acceptJson()
                ->withToken($readToken)
                ->post($itemsUrl, ['refs' => $chunk]);

            if ($response->status() === 404) {
                return null;
            }

            if (! $response->successful()) {
                Log::warning('SeoContentAi sync items chunk failed', [
                    'site_id' => $site->id,
                    'status' => $response->status(),
                    'body' => mb_substr((string) $response->body(), 0, 300),
                ]);

                return null;
            }

            $payload = $response->json();
            if (! is_array($payload) || ! ($payload['success'] ?? false)) {
                return null;
            }

            $batch = is_array($payload['items'] ?? null) ? $payload['items'] : [];
            foreach ($batch as $item) {
                if (is_array($item)) {
                    $items[] = $item;
                }
            }
        }

        return $items;
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    private function fetchFullSyncItems(Site $site, string $readToken): ?array
    {
        $response = Http::timeout(300)
            ->acceptJson()
            ->withToken($readToken)
            ->get($this->buildSyncUrl($site), [
                'is_test' => 0,
                'limit_per_type' => 0,
            ]);

        if (! $response->successful()) {
            return null;
        }

        $payload = $response->json();
        if (! is_array($payload) || ! ($payload['success'] ?? false)) {
            return null;
        }

        $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];

        return array_values(array_filter($items, static fn (mixed $item): bool => is_array($item)));
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<int, array<string, mixed>>  $refs
     * @return array<int, array<string, mixed>>
     */
    private function filterItemsByRefs(array $items, array $refs): array
    {
        $wanted = [];
        foreach ($refs as $ref) {
            $type = strtolower(trim((string) ($ref['type'] ?? '')));
            $wpId = (int) ($ref['wp_id'] ?? 0);
            if ($type !== '' && $wpId > 0) {
                $wanted[$type.'|'.$wpId] = true;
            }
        }

        if ($wanted === []) {
            return [];
        }

        return array_values(array_filter(
            $items,
            static function (mixed $item) use ($wanted): bool {
                if (! is_array($item)) {
                    return false;
                }

                $type = strtolower(trim((string) ($item['type'] ?? '')));
                $wpId = (int) ($item['wp_id'] ?? 0);

                return isset($wanted[$type.'|'.$wpId]);
            },
        ));
    }

    /**
     * @param  array<int, array<string, mixed>>  $refs
     * @return array<int, array<string, mixed>>
     */
    private function fetchItemsViaFullSyncFilter(Site $site, string $readToken, array $refs): array
    {
        $allItems = $this->fetchFullSyncItems($site, $readToken);

        return $allItems === null ? [] : $this->filterItemsByRefs($allItems, $refs);
    }

    /**
     * @return array<string, int>
     */
    private function emptySyncedCounts(): array
    {
        return [
            'article' => 0,
            'product' => 0,
            'category' => 0,
            'product_category' => 0,
            'other' => 0,
        ];
    }

    /**
     * Nhận payload đẩy từ plugin WordPress (hook save_post / term).
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array{success:bool,message:string,synced?:array<string,int>}
     */
    public function importPushedItems(Site $site, array $items): array
    {
        $platform = (string) ($site->getMeta('seo_platform') ?? '');
        if ($platform !== 'wordpress') {
            return [
                'success' => false,
                'message' => 'Site chưa cấu hình nền tảng WordPress.',
            ];
        }

        $synced = $this->importItems($site, $items);

        return $this->buildImportSuccessResponse($synced, false, []);
    }

    /**
     * @param  array<string, int>  $synced
     * @param  array<string, int>  $counts
     * @return array{success:bool,message:string,synced:array<string,int>,counts?:array<string,int>}
     */
    private function buildImportSuccessResponse(array $synced, bool $isTest, array $counts): array
    {
        $response = [
            'success' => true,
            'message' => sprintf(
                'Đồng bộ thành công %d mục từ WordPress%s.',
                array_sum($synced),
                $isTest ? ' (chế độ test)' : ''
            ),
            'synced' => $synced,
        ];

        if ($counts !== []) {
            $response['counts'] = $counts;
        }

        return $response;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<string, int>
     */
    public function importItems(Site $site, array $items): array
    {
        $synced = [
            'article' => 0,
            'product' => 0,
            'category' => 0,
            'product_category' => 0,
            'other' => 0,
        ];

        $analyzer = app(SeoAnalyzerService::class);
        $userId = (int) $site->user_id;
        $siteDomain = (string) $site->domain;

        $syncFlags = app(ArticleWordPressSyncFlagService::class);

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $wpId = (int) ($item['wp_id'] ?? 0);
            if ($wpId <= 0) {
                continue;
            }

            try {
                $this->importSingleSyncItem(
                    site: $site,
                    item: $item,
                    wpId: $wpId,
                    synced: $synced,
                    analyzer: $analyzer,
                    siteDomain: $siteDomain,
                    userId: $userId,
                    syncFlags: $syncFlags,
                );
            } catch (Throwable $e) {
                Log::warning('SeoContentAi sync item failed', [
                    'site_id' => $site->id,
                    'wp_id' => $wpId,
                    'type' => (string) ($item['type'] ?? 'article'),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $synced;
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, int>  $synced
     */
    private function importSingleSyncItem(
        Site $site,
        array $item,
        int $wpId,
        array &$synced,
        SeoAnalyzerService $analyzer,
        string $siteDomain,
        int $userId,
        ArticleWordPressSyncFlagService $syncFlags,
    ): void {
        $type = $this->normalizeType((string) ($item['type'] ?? 'article'));
        $publishedAt = $this->parsePublishedAt($item['published_at'] ?? null);

        $existing = SeoArticle::query()
            ->where('site_id', $site->id)
            ->where('wp_post_id', $wpId)
            ->where('type', $type)
            ->first();

        if ($existing instanceof SeoArticle && $syncFlags->shouldBlockWordPressImport($existing)) {
            $syncFlags->markDataOutOfSync($existing);

            if (array_key_exists('conflict', $synced)) {
                $synced['conflict']++;
            } else {
                $synced['conflict'] = 1;
            }

            return;
        }

        $title = $this->resolveSyncItemTitle($item, $syncFlags);
        $hasLocalBody = $existing instanceof SeoArticle && $syncFlags->hasLocalEditorContent($existing);

        // Bài chưa có nội dung editor trên SEO: ghi đè scoring (xóa slug/body). Bài đã có body: chỉ cập nhật tiêu đề/trạng thái.
        $articleAttributes = [
            'type' => $type,
            'title' => $title !== '' ? $title : 'Untitled',
            'status' => $this->normalizeStatus((string) ($item['status'] ?? 'draft')),
            'published_at' => $publishedAt,
        ];

        if (! $hasLocalBody) {
            $articleAttributes['slug'] = null;
            $articleAttributes['excerpt'] = null;
            $articleAttributes['body'] = null;
            $articleAttributes['blocks'] = null;
        }

        $article = SeoArticle::query()->updateOrCreate(
            [
                'site_id' => $site->id,
                'wp_post_id' => $wpId,
                'type' => $type,
            ],
            $articleAttributes,
        );

        if ($title !== '') {
            $article->articleMetas()->updateOrCreate(
                ['meta_key' => 'wp_post_title'],
                ['meta_value' => $title],
            );
        }

        $wpPostType = (string) ($item['wp_post_type'] ?? '');
        if ($wpPostType !== '') {
            $article->articleMetas()->updateOrCreate(
                ['meta_key' => 'wp_post_type'],
                ['meta_value' => $wpPostType]
            );
        }

        $wpEntity = trim((string) ($item['wp_entity'] ?? ''));
        if ($wpEntity !== '') {
            $article->articleMetas()->updateOrCreate(
                ['meta_key' => 'wp_entity'],
                ['meta_value' => $wpEntity],
            );
        }

        if ($wpEntity === 'term' && $wpPostType !== '') {
            $article->articleMetas()->updateOrCreate(
                ['meta_key' => 'wp_taxonomy'],
                ['meta_value' => $wpPostType],
            );
        }

        $this->syncWordPressPostMeta($article, $item);
        $this->syncSchemaAndWooCommerceMeta($article, $item);
        app(ArticlePostImagesService::class)->importFromSyncItem($article, $item);

        if (! $hasLocalBody) {
            app(ArticleFaqWordPressImportService::class)->importFromWordPressSyncItem($article, $item);
        }

        $this->syncSeoMetaFromWordPress($article, $item);
        $this->syncFocusKeyword($site, $userId, $article, $item);
        app(WordPressArticleSyncService::class)->applyMultilingualFromSyncPayload($article, $site, $item);

        $syncFlags->clearAll($article);

        if (array_key_exists($type, $synced)) {
            $synced[$type]++;
        } else {
            $synced['other']++;
        }

        try {
            $analyzer->analyzeFromSyncItem($article, $item, $siteDomain);
        } catch (Throwable $e) {
            Log::warning('SeoAnalyzer failed after sync', [
                'article_id' => $article->id,
                'error' => $e->getMessage(),
            ]);
        }

        $this->timestampService->sync($article, $item);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function syncWordPressPostMeta(SeoArticle $article, array $item): void
    {
        $type = $this->normalizeType((string) ($item['type'] ?? 'article'));
        $isTaxonomy = in_array($type, ['category', 'product_category'], true);
        $content = $this->resolveSyncItemContent($item);

        if ($isTaxonomy || $content !== '') {
            $article->articleMetas()->updateOrCreate(
                ['meta_key' => 'wp_post_content'],
                ['meta_value' => $content],
            );

            app(ArticleFaqWordPressRestoreService::class)->persistWordPressSourceSnapshot($article, $content);
            $this->extractTocAfterWordPressContentSync($article);
        }

        $slug = trim((string) ($item['slug'] ?? ''));
        $normalizedSlug = RankMathSeoValueNormalizer::normalizeSlug($slug);
        if ($normalizedSlug !== null && $normalizedSlug !== '') {
            $article->articleMetas()->updateOrCreate(
                ['meta_key' => 'wp_slug'],
                ['meta_value' => $normalizedSlug],
            );
        } elseif ($slug !== '' && RankMathSeoValueNormalizer::containsRankMathVariable($slug)) {
            $article->articleMetas()->where('meta_key', 'wp_slug')->delete();
        }

        $permalink = trim((string) ($item['permalink'] ?? ''));
        if ($permalink !== '') {
            $article->loadMissing('site');
            if ($article->site instanceof Site) {
                $permalink = app(WordPressPermalinkBuilder::class)->resolveFromSyncItem($article->site, $item);
            }
            $article->articleMetas()->updateOrCreate(
                ['meta_key' => 'wp_permalink'],
                ['meta_value' => $permalink],
            );
        }

        if ($isTaxonomy) {
            $parentId = max(0, (int) ($item['parent_id'] ?? 0));
            if ($parentId > 0) {
                $article->articleMetas()->updateOrCreate(
                    ['meta_key' => 'wp_parent_id'],
                    ['meta_value' => (string) $parentId],
                );
            } else {
                $article->articleMetas()->where('meta_key', 'wp_parent_id')->delete();
            }

            $article->articleMetas()->whereIn('meta_key', [
                'wp_featured_image_url',
                'wp_product_gallery',
            ])->delete();

            return;
        }

        $featuredImageUrl = trim((string) ($item['featured_image_url'] ?? ''));
        if ($featuredImageUrl !== '') {
            $article->articleMetas()->updateOrCreate(
                ['meta_key' => 'wp_featured_image_url'],
                ['meta_value' => $featuredImageUrl],
            );
        }

        $gallery = $item['product_gallery'] ?? null;
        if (is_array($gallery) && $gallery !== []) {
            $article->articleMetas()->updateOrCreate(
                ['meta_key' => 'wp_product_gallery'],
                ['meta_value' => json_encode($gallery, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
            );
        }

        $virtualComments = $item['virtual_comments'] ?? null;
        if (is_array($virtualComments) && $virtualComments !== []) {
            $article->articleMetas()->updateOrCreate(
                ['meta_key' => VirtualCommentService::ARTICLE_META_KEY],
                ['meta_value' => json_encode($virtualComments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
            );
        }
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function resolveSyncItemTitle(array $item, ArticleWordPressSyncFlagService $syncFlags): string
    {
        $raw = (string) ($item['title'] ?? $item['post_title'] ?? '');

        return $syncFlags->decodeWordPressText($raw);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function resolveSyncItemContent(array $item): string
    {
        $content = trim((string) ($item['post_content'] ?? ''));
        if ($content !== '') {
            return $content;
        }

        $scoring = is_array($item['scoring'] ?? null) ? $item['scoring'] : [];

        return trim((string) ($scoring['body'] ?? ''));
    }

    /**
     * @param  array<string, mixed>  $item
     */
    /**
     * @param  array<string, mixed>  $item
     */
    private function syncSchemaAndWooCommerceMeta(SeoArticle $article, array $item): void
    {
        $woocommerce = is_array($item['woocommerce'] ?? null) ? $item['woocommerce'] : [];
        if ($woocommerce === []) {
            return;
        }

        $currency = strtoupper(trim((string) ($woocommerce['currency'] ?? 'VND')));

        $map = [
            '_price' => (string) ($woocommerce['price'] ?? ''),
            'regular_price' => (string) ($woocommerce['regular_price'] ?? ''),
            '_regular_price' => (string) ($woocommerce['regular_price'] ?? ''),
            'sale_price' => (string) ($woocommerce['sale_price'] ?? ''),
            '_sale_price' => (string) ($woocommerce['sale_price'] ?? ''),
            'min_price' => (string) ($woocommerce['min_price'] ?? ''),
            'max_price' => (string) ($woocommerce['max_price'] ?? ''),
            'price_currency' => $currency !== '' ? $currency : 'VND',
        ];

        foreach ($map as $metaKey => $metaValue) {
            $metaValue = trim($metaValue);
            if ($metaValue === '') {
                continue;
            }

            $article->articleMetas()->updateOrCreate(
                ['meta_key' => $metaKey],
                ['meta_value' => $metaValue],
            );
        }
    }

    private function extractTocAfterWordPressContentSync(SeoArticle $article): void
    {
        try {
            $this->tocExtraction->extractForArticle($article);
        } catch (Throwable $e) {
            Log::warning('TOC extraction failed after WordPress content sync', [
                'article_id' => $article->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function syncSeoMetaFromWordPress(SeoArticle $article, array $item): void
    {
        $article->articleMetas()->where('meta_key', 'seo_plugin')->delete();

        $seo = is_array($item['seo'] ?? null) ? $item['seo'] : [];

        $seoTitle = RankMathSeoValueNormalizer::normalizeTitle(
            (string) ($seo['seo_title'] ?? ''),
        );
        $rawTitle = trim((string) ($seo['seo_title'] ?? ''));

        $metaMap = [
            'seo_meta_description' => (string) ($seo['meta_description'] ?? ''),
            'seo_focus_keyword' => Keyword::preparePhraseForStorage((string) ($seo['focus_keyword'] ?? '')),
        ];

        if ($seoTitle !== null && $seoTitle !== '') {
            $metaMap['seo_title'] = $seoTitle;
        } elseif ($rawTitle !== '') {
            $article->articleMetas()->where('meta_key', 'seo_title')->delete();
        }

        foreach ($metaMap as $metaKey => $metaValue) {
            $metaValue = trim($metaValue);
            if ($metaValue === '') {
                if ($metaKey === 'seo_focus_keyword') {
                    $article->articleMetas()->where('meta_key', $metaKey)->delete();
                }

                continue;
            }

            $article->articleMetas()->updateOrCreate(
                ['meta_key' => $metaKey],
                ['meta_value' => $metaValue]
            );
        }
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function syncFocusKeyword(Site $site, int $userId, SeoArticle $article, array $item): void
    {
        $seo = is_array($item['seo'] ?? null) ? $item['seo'] : [];
        $scoring = is_array($item['scoring'] ?? null) ? $item['scoring'] : [];

        $phrase = Keyword::preparePhraseForStorage(
            (string) ($seo['focus_keyword'] ?? $scoring['focus_keyword'] ?? ''),
        );
        if ($phrase === '') {
            return;
        }

        KeywordFocusAttach::syncMainKeyword($article, $site->id, $userId, $phrase);
    }

    /**
     * @param  array<string, int>  $counts
     * @param  array<string, int>  $totals
     */
    private function persistManifestCounts(Site $site, array $counts, array $totals = []): void
    {
        $payload = [
            'counts' => $counts,
            'totals' => $totals,
            'fetched_at' => now()->toIso8601String(),
        ];

        $site->metas()->updateOrCreate(
            ['meta_key' => 'seo_wp_manifest_counts'],
            ['meta_value' => json_encode($payload, JSON_UNESCAPED_UNICODE)],
        );
    }

    /**
     * @param  array<string, int>  $manifestCounts
     */
    private function buildManifestGapMessage(array $manifestCounts, int $localArticleCount): string
    {
        $wpPosts = (int) ($manifestCounts['article'] ?? 0);
        $wpPages = (int) ($manifestCounts['page'] ?? 0);
        $wpArticleTotal = $wpPosts + $wpPages;

        if ($wpArticleTotal <= 0 || $localArticleCount >= $wpArticleTotal) {
            return '';
        }

        $missing = $wpArticleTotal - $localArticleCount;

        return sprintf(
            'WordPress có %d bài (post %d + page %d) nhưng local chỉ có %d — thiếu %d bài. Hãy cập nhật plugin WP hoặc dùng «Làm sạch & Đồng bộ lại» nếu vẫn lệch.',
            $wpArticleTotal,
            $wpPosts,
            $wpPages,
            $localArticleCount,
            $missing,
        );
    }

    private function buildSyncUrl(Site $site): string
    {
        return $this->buildSiteBaseUrl($site).'/wp-json/omi-seo-ai/v1/sync';
    }

    private function buildSyncManifestUrl(Site $site): string
    {
        return $this->buildSiteBaseUrl($site).'/wp-json/omi-seo-ai/v1/sync/manifest';
    }

    private function buildSyncItemsUrl(Site $site): string
    {
        return $this->buildSiteBaseUrl($site).'/wp-json/omi-seo-ai/v1/sync/items';
    }

    private function buildSiteBaseUrl(Site $site): string
    {
        $domain = trim((string) $site->domain);
        if ($domain === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $domain)) {
            return rtrim($domain, '/');
        }

        $scheme = ! empty($site->ssl) ? 'https' : 'http';

        return $scheme.'://'.rtrim($domain, '/');
    }

    private function normalizeType(string $type): string
    {
        $type = strtolower(trim($type));

        return match ($type) {
            'article', 'post', 'page' => 'article',
            'product' => 'product',
            'category' => 'category',
            'product_category', 'product_cat' => 'product_category',
            default => $type !== '' ? $type : 'article',
        };
    }

    private function normalizeStatus(string $status): string
    {
        $status = strtolower(trim($status));

        return match ($status) {
            'publish', 'published' => 'published',
            'future' => 'scheduled',
            'private' => 'private',
            default => 'draft',
        };
    }

    private function parsePublishedAt(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value);
        } catch (Throwable) {
            return null;
        }
    }
}
