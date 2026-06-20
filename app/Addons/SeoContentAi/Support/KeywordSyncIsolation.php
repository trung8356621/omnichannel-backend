<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Support;

/**
 * Cô lập luồng keywords: chỉ cho phép đồng bộ qua rescrap domain (site),
 * không tự động ghi keyword/link khi save bài, observer, hay link list.
 */
final class KeywordSyncIsolation
{
    private static int $domainResyncDepth = 0;

    public static function allowsAutomaticContentSync(): bool
    {
        return false;
    }

    public static function allowsKeywordObserverSync(): bool
    {
        return false;
    }

    public static function allowsDomainLinkListSync(): bool
    {
        return false;
    }

    public static function allowsContentKeywordPersistence(): bool
    {
        return self::$domainResyncDepth > 0;
    }

    public static function allowsDomainResync(): bool
    {
        return true;
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public static function runWithinDomainResync(callable $callback): mixed
    {
        self::$domainResyncDepth++;

        try {
            return $callback();
        } finally {
            self::$domainResyncDepth--;
        }
    }
}
