<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Support\ContentProject;

use InvalidArgumentException;

/**
 * Canonical Content Project item identity:
 * filled(keyword) || filled(post_title).
 *
 * Shared by Filament, sync normalizer, Command Bus, MCP/Agent, and generation guards.
 */
final class ContentProjectItemIdentity
{
    public static function normalize(?string $value): string
    {
        return trim((string) $value);
    }

    public static function isValid(?string $keyword, ?string $postTitle): bool
    {
        return self::normalize($keyword) !== '' || self::normalize($postTitle) !== '';
    }

    /**
     * Internal topic for prompt assembly only — never persist as if user entered post_title.
     */
    public static function topic(?string $postTitle, ?string $keyword): string
    {
        $title = self::normalize($postTitle);
        if ($title !== '') {
            return $title;
        }

        return self::normalize($keyword);
    }

    public static function failureMessage(): string
    {
        try {
            $translated = (string) __('seo-content-ai::filament.projects.keyword_or_title_required');
            if (
                $translated !== ''
                && $translated !== 'seo-content-ai::filament.projects.keyword_or_title_required'
            ) {
                return $translated;
            }
        } catch (\Throwable) {
            // Pure PHPUnit / no translator.
        }

        return 'Vui lòng nhập ít nhất Từ khóa hoặc Tiêu đề.';
    }

    public static function assertValid(?string $keyword, ?string $postTitle): void
    {
        if (! self::isValid($keyword, $postTitle)) {
            throw new InvalidArgumentException(self::failureMessage());
        }
    }
}
