<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Support;

final class ApiConnectionProviders
{
    public const GEMINI = 'gemini';

    public const CLAUDE = 'claude';

    public const GOOGLE_SEARCH_CONSOLE = 'google_search_console';

    public const DATAFORSEO = 'dataforseo';

    public const SERPER = 'serper';

    public const SERPAPI = 'serpapi';

    public const SEARCHAPI = 'searchapi';

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::GEMINI => __('seo-content-ai::filament.api_connections.provider_gemini'),
            self::CLAUDE => __('seo-content-ai::filament.api_connections.provider_claude'),
            self::GOOGLE_SEARCH_CONSOLE => __('seo-content-ai::filament.api_connections.provider_gsc'),
            self::DATAFORSEO => __('seo-content-ai::filament.api_connections.provider_dataforseo'),
            self::SERPER => __('seo-content-ai::filament.api_connections.provider_serper'),
            self::SERPAPI => __('seo-content-ai::filament.api_connections.provider_serpapi'),
            self::SEARCHAPI => __('seo-content-ai::filament.api_connections.provider_searchapi'),
        ];
    }

    public static function label(string $provider): string
    {
        return self::options()[$provider] ?? $provider;
    }

    public static function isAi(?string $provider): bool
    {
        return in_array($provider, [self::GEMINI, self::CLAUDE], true);
    }

    public static function isExternal(?string $provider): bool
    {
        return in_array($provider, [
            self::GOOGLE_SEARCH_CONSOLE,
            self::DATAFORSEO,
            self::SERPER,
            self::SERPAPI,
            self::SEARCHAPI,
        ], true);
    }

    public static function isSerpProvider(?string $provider): bool
    {
        return in_array($provider, [self::SERPER, self::SERPAPI, self::SEARCHAPI], true);
    }
}
