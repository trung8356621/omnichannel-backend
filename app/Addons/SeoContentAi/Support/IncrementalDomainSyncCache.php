<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Support;

final class IncrementalDomainSyncCache
{
    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public static function cacheKey(int $userId, int $siteId): string
    {
        return 'seo_domain_incr_sync:'.$userId.':'.$siteId;
    }

    public static function fullItemsCacheKey(string $cacheKey): string
    {
        return $cacheKey.'_full_items';
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array{
     *     done: int,
     *     total: int,
     *     status: string,
     *     running: bool,
     *     message: ?string
     * }
     */
    public static function progressFromState(?array $state): array
    {
        if (! is_array($state) || ! is_array($state['refs'] ?? null)) {
            return [
                'done' => 0,
                'total' => 0,
                'status' => '',
                'running' => false,
                'message' => null,
            ];
        }

        $refs = $state['refs'];
        $total = count($refs);
        $done = min($total, (int) ($state['offset'] ?? 0));
        $status = (string) ($state['status'] ?? self::STATUS_RUNNING);

        return [
            'done' => $done,
            'total' => $total,
            'status' => $status,
            'running' => $status === self::STATUS_RUNNING && $done < $total,
            'message' => isset($state['message']) ? (string) $state['message'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $prepared
     * @return array<string, mixed>
     */
    public static function initialState(array $prepared, array $refs): array
    {
        return [
            'status' => self::STATUS_RUNNING,
            'refs' => $refs,
            'offset' => 0,
            'skipped' => (int) ($prepared['skipped'] ?? 0),
            'new_count' => (int) ($prepared['new_count'] ?? 0),
            'update_count' => (int) ($prepared['update_count'] ?? 0),
            'accumulated_synced' => [
                'article' => 0,
                'product' => 0,
                'category' => 0,
                'product_category' => 0,
                'other' => 0,
            ],
            'chunk_state' => [],
            'started_at' => now()->toIso8601String(),
            'message' => null,
        ];
    }
}
