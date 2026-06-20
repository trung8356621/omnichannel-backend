<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Support;

use Illuminate\Support\Facades\Cache;

final class KeywordDomainResyncCache
{
    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public static function cacheKey(int $userId, int $siteId): string
    {
        return 'seo_keyword_domain_resync:'.$userId.':'.$siteId;
    }

    public static function markRunning(int $userId, int $siteId): void
    {
        Cache::put(self::cacheKey($userId, $siteId), [
            'status' => self::STATUS_RUNNING,
            'started_at' => now()->toIso8601String(),
        ], now()->addHours(2));
    }

    /**
     * @param  array<string, mixed>  $result
     */
    public static function markCompleted(int $userId, int $siteId, array $result): void
    {
        Cache::put(self::cacheKey($userId, $siteId), [
            'status' => self::STATUS_COMPLETED,
            'result' => $result,
            'finished_at' => now()->toIso8601String(),
        ], now()->addHour());
    }

    public static function markFailed(int $userId, int $siteId, string $message): void
    {
        Cache::put(self::cacheKey($userId, $siteId), [
            'status' => self::STATUS_FAILED,
            'message' => $message,
            'finished_at' => now()->toIso8601String(),
        ], now()->addHour());
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function read(int $userId, int $siteId): ?array
    {
        $state = Cache::get(self::cacheKey($userId, $siteId));

        return is_array($state) ? $state : null;
    }

    public static function isRunning(int $userId, int $siteId): bool
    {
        self::clearIfStale($userId, $siteId);

        return (self::read($userId, $siteId)['status'] ?? '') === self::STATUS_RUNNING;
    }

    public static function clearIfStale(int $userId, int $siteId, int $maxAgeSeconds = 900): void
    {
        $state = self::read($userId, $siteId);
        if (! is_array($state) || ($state['status'] ?? '') !== self::STATUS_RUNNING) {
            return;
        }

        $startedAt = isset($state['started_at']) ? strtotime((string) $state['started_at']) : false;
        if ($startedAt !== false && (time() - $startedAt) < $maxAgeSeconds) {
            return;
        }

        Cache::forget(self::cacheKey($userId, $siteId));
    }

    public static function clear(int $userId, int $siteId): void
    {
        Cache::forget(self::cacheKey($userId, $siteId));
    }

    /**
     * @param  array<string, mixed>|null  $state
     * @return array{running: bool, status: string, message: ?string, result: ?array<string, mixed>}
     */
    public static function progressFromState(?array $state): array
    {
        if (! is_array($state)) {
            return [
                'running' => false,
                'status' => '',
                'message' => null,
                'result' => null,
            ];
        }

        $status = (string) ($state['status'] ?? '');

        return [
            'running' => $status === self::STATUS_RUNNING,
            'status' => $status,
            'message' => isset($state['message']) ? (string) $state['message'] : null,
            'result' => is_array($state['result'] ?? null) ? $state['result'] : null,
        ];
    }
}
