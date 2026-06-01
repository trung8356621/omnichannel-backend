<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Support;

use App\Addons\SeoContentAi\Models\SeoPrompt;

final class PromptPostProcessing
{
    /**
     * @return array{
     *     split_enabled: bool,
     *     split_rows: int,
     *     split_columns: int,
     *     resize_enabled: bool,
     *     resize_width: int|null,
     *     resize_height: int|null,
     * }
     */
    public static function defaults(): array
    {
        return [
            'split_enabled' => false,
            'split_rows' => 3,
            'split_columns' => 2,
            'resize_enabled' => false,
            'resize_width' => null,
            'resize_height' => null,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $settings
     * @return array{
     *     split_enabled: bool,
     *     split_rows: int,
     *     split_columns: int,
     *     resize_enabled: bool,
     *     resize_width: int|null,
     *     resize_height: int|null,
     * }
     */
    public static function fromPromptSettings(?array $settings): array
    {
        $raw = is_array($settings['post_processing'] ?? null)
            ? $settings['post_processing']
            : [];

        return self::normalize($raw);
    }

    public static function fromPrompt(SeoPrompt $prompt): array
    {
        $settings = is_array($prompt->settings) ? $prompt->settings : [];

        return self::fromPromptSettings($settings);
    }

    /**
     * @param  array{
     *     split_enabled: bool,
     *     split_rows: int,
     *     split_columns: int,
     *     resize_enabled: bool,
     *     resize_width: int|null,
     *     resize_height: int|null,
     * }  $config
     */
    public static function isActive(array $config): bool
    {
        if ($config['split_enabled']) {
            return true;
        }

        return $config['resize_enabled']
            && ($config['resize_width'] !== null || $config['resize_height'] !== null);
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array{
     *     split_enabled: bool,
     *     split_rows: int,
     *     split_columns: int,
     *     resize_enabled: bool,
     *     resize_width: int|null,
     *     resize_height: int|null,
     * }
     */
    public static function normalize(array $raw): array
    {
        $defaults = self::defaults();

        $rows = (int) ($raw['split_rows'] ?? $defaults['split_rows']);
        $cols = (int) ($raw['split_columns'] ?? $defaults['split_columns']);

        return [
            'split_enabled' => filter_var($raw['split_enabled'] ?? false, FILTER_VALIDATE_BOOL),
            'split_rows' => max(1, min(12, $rows > 0 ? $rows : $defaults['split_rows'])),
            'split_columns' => max(1, min(12, $cols > 0 ? $cols : $defaults['split_columns'])),
            'resize_enabled' => filter_var($raw['resize_enabled'] ?? false, FILTER_VALIDATE_BOOL),
            'resize_width' => self::positiveIntOrNull($raw['resize_width'] ?? null),
            'resize_height' => self::positiveIntOrNull($raw['resize_height'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $settings
     * @return array<string, mixed>
     */
    public static function mergeIntoSettings(?array $settings, ?array $postProcessing): array
    {
        $settings = is_array($settings) ? $settings : [];

        $settings['post_processing'] = self::normalize(
            is_array($postProcessing) ? $postProcessing : [],
        );

        return $settings;
    }

    private static function positiveIntOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $int = (int) $value;

        return $int > 0 ? $int : null;
    }
}
