<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Enums;

/**
 * Task lifecycle statuses — bao gồm legacy + status mới cho Phase 3+.
 * Model vẫn lưu string; không auto-cast Eloquent sang enum ở Phase 2.
 */
enum SeoProjectTaskStatus: string
{
    case Draft = 'draft';
    case Pending = 'pending';
    case Writing = 'writing';
    case Processing = 'processing';
    case Reviewing = 'reviewing';
    case Completed = 'completed';
    case Failed = 'failed';
    case Archived = 'archived';
    case Cancelled = 'cancelled';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }

    /**
     * @return list<string>
     */
    public static function legacyValues(): array
    {
        return [
            self::Pending->value,
            self::Writing->value,
            self::Reviewing->value,
            self::Completed->value,
            self::Failed->value,
        ];
    }

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed,
            self::Failed,
            self::Archived,
            self::Cancelled => true,
            default => false,
        };
    }

    public function isActive(): bool
    {
        return match ($this) {
            self::Pending,
            self::Writing,
            self::Processing,
            self::Reviewing => true,
            default => false,
        };
    }

    public function isDraft(): bool
    {
        return $this === self::Draft;
    }

    public static function tryFromString(?string $value): ?self
    {
        if ($value === null || $value === '') {
            return null;
        }

        return self::tryFrom($value);
    }
}
