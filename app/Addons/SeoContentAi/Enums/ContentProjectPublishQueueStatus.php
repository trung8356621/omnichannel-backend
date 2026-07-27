<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Enums;

/**
 * Trạng thái Publishing Queue trên Content Project Item (SaaS-owned).
 */
enum ContentProjectPublishQueueStatus: string
{
    case None = 'none';
    case Waiting = 'waiting';
    case Processing = 'processing';
    case Retrying = 'retrying';
    case Published = 'published';
    case Failed = 'failed';
    case Skipped = 'skipped';
    case Cancelled = 'cancelled';

    public function isActiveQueue(): bool
    {
        return in_array($this, [
            self::Waiting,
            self::Processing,
            self::Retrying,
        ], true);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::Published,
            self::Skipped,
            self::Cancelled,
        ], true);
    }

    /**
     * @return list<string>
     */
    public static function activeValues(): array
    {
        return [
            self::Waiting->value,
            self::Processing->value,
            self::Retrying->value,
        ];
    }
}
