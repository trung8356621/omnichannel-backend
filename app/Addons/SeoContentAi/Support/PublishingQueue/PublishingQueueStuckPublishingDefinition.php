<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Support\PublishingQueue;

use Carbon\Carbon;

/**
 * Stuck Publishing — processing quá TTL, thiếu attempt, hoặc đã lố lịch (past due).
 */
final class PublishingQueueStuckPublishingDefinition
{
    public const TTL_MINUTES = 30;

    /** Schedule đã qua + vẫn processing → stuck sớm hơn TTL (Retry không dùng được). */
    public const PAST_DUE_GRACE_MINUTES = 5;

    public const FAILURE_TYPE = 'stale_processing';

    /**
     * @param  array<string, mixed>  $row
     */
    public static function matches(array $row, ?int $ttlMinutes = null): bool
    {
        if (! PublishingQueuePublishingDefinition::matches($row)) {
            return false;
        }

        if (self::isPastDueStuck($row)) {
            return true;
        }

        $started = self::startedAt($row);
        if ($started === null) {
            return true;
        }

        $ttl = max(1, $ttlMinutes ?? self::TTL_MINUTES);

        return $started->lte(now()->subMinutes($ttl));
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function startedAt(array $row): ?Carbon
    {
        foreach (['publishing_started_at', 'last_publish_attempt_at', 'last_publish_attempt'] as $key) {
            $raw = $row[$key] ?? null;
            if ($raw instanceof Carbon) {
                return $raw;
            }
            if (is_string($raw) && trim($raw) !== '') {
                try {
                    return Carbon::parse($raw);
                } catch (\Throwable) {
                    continue;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function isPastDueStuck(array $row, ?int $graceMinutes = null): bool
    {
        $scheduled = self::scheduledAt($row);
        if ($scheduled === null) {
            return false;
        }

        $grace = max(0, $graceMinutes ?? self::PAST_DUE_GRACE_MINUTES);

        return $scheduled->lte(now()->subMinutes($grace));
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function scheduledAt(array $row): ?Carbon
    {
        foreach (['scheduled_publish_at', 'scheduled_raw'] as $key) {
            $raw = $row[$key] ?? null;
            if ($raw instanceof Carbon) {
                return $raw;
            }
            if (is_string($raw) && trim($raw) !== '') {
                try {
                    return Carbon::parse($raw);
                } catch (\Throwable) {
                    continue;
                }
            }
        }

        return null;
    }
}
