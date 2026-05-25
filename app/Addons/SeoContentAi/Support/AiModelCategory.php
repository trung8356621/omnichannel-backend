<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Support;

/**
 * Nhãn đại diện (Unified Category) — người dùng chọn; hệ thống map sang raw model trong seo_ai_models.
 */
final class AiModelCategory
{
    public const GEMINI_PRO = 'gemini_pro';

    public const GEMINI_FLASH = 'gemini_flash';

    public const IMAGEN_PRO = 'imagen_pro';

    public const CLAUDE_OPUS = 'claude_opus';

    public const CLAUDE_SONNET = 'claude_sonnet';

    public const CLAUDE_HAIKU = 'claude_haiku';

    /**
     * @return array<string, string>
     */
    public static function promptSelectOptions(): array
    {
        return [
            self::GEMINI_PRO => 'GEMINI Pro (Dàn ý, phân tích sâu)',
            self::GEMINI_FLASH => 'GEMINI Flash (Tốc độ & chi phí)',
            self::IMAGEN_PRO => 'GEMINI Image Pro (Ảnh chất lượng cao)',
            self::CLAUDE_OPUS => 'CLAUDE Opus (Chất lượng đỉnh cao)',
            self::CLAUDE_SONNET => 'CLAUDE Sonnet (Viết bài chuẩn SEO)',
            self::CLAUDE_HAIKU => 'CLAUDE Haiku (Nhanh & tiết kiệm)',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function connectionSelectOptions(string $provider): array
    {
        return match ($provider) {
            'gemini' => [
                self::GEMINI_FLASH => 'GEMINI Flash (Tốc độ & chi phí)',
                self::GEMINI_PRO => 'GEMINI Pro (Dàn ý, phân tích sâu)',
                self::IMAGEN_PRO => 'GEMINI Image Pro (Ảnh chất lượng cao)',
            ],
            'claude' => [
                self::CLAUDE_SONNET => 'CLAUDE Sonnet (Viết bài chuẩn SEO)',
                self::CLAUDE_OPUS => 'CLAUDE Opus (Chất lượng đỉnh cao)',
                self::CLAUDE_HAIKU => 'CLAUDE Haiku (Nhanh & tiết kiệm)',
            ],
            default => [],
        };
    }

    public static function isValid(string $category): bool
    {
        return array_key_exists($category, self::promptSelectOptions());
    }

    public static function defaultForProvider(string $provider): string
    {
        return match ($provider) {
            'claude' => self::CLAUDE_SONNET,
            default => self::GEMINI_FLASH,
        };
    }

    /**
     * Chọn category theo prompt + công cụ (image → imagen_pro).
     */
    public static function resolveForPrompt(?string $modelCategory, string $provider, string $toolType = 'default'): string
    {
        if ($toolType === 'image') {
            return self::IMAGEN_PRO;
        }

        if ($modelCategory !== null && $modelCategory !== '' && self::isValid($modelCategory)) {
            return $modelCategory;
        }

        return self::defaultForProvider($provider);
    }

    /**
     * Category hợp lệ với nhà cung cấp.
     */
    public static function matchesProvider(string $category, string $provider): bool
    {
        return match ($provider) {
            'gemini' => in_array($category, [self::GEMINI_PRO, self::GEMINI_FLASH, self::IMAGEN_PRO], true),
            'claude' => in_array($category, [self::CLAUDE_OPUS, self::CLAUDE_SONNET, self::CLAUDE_HAIKU], true),
            default => false,
        };
    }
}
