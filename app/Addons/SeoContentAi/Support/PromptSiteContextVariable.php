<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Support;

use App\Addons\SeoContentAi\Filament\Resources\PromptResource;
use App\Addons\SeoContentAi\Models\SeoPrompt;
use App\Addons\SeoContentAi\Services\SeoPromptSettingsService;
use App\Addons\SeoContentAi\Services\SiteDomainPromptContextService;
use App\Models\Site;

/**
 * Biến gắn domain (Technical SEO + Prompt settings) — tự điền theo tên miền chọn trên header.
 */
final class PromptSiteContextVariable
{
    public const POST_TYPE_FIELD = '_test_post_type';

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return [
            'site_domain',
            'site_short_description',
            'site_cta',
            'site_links',
            'tone',
            'article_length',
            'keyword_density',
            'article_length_product',
            'article_length_default',
            'keyword_density_product',
            'keyword_density_default',
        ];
    }

    public static function isName(string $name): bool
    {
        return in_array(trim($name), self::names(), true);
    }

    public static function usesInPrompt(SeoPrompt $prompt): bool
    {
        foreach (PromptResource::extractVariableNamesFromMarkdown((string) ($prompt->markdown_content ?? '')) as $name) {
            if (self::isName($name)) {
                return true;
            }
        }

        foreach (is_array($prompt->variables) ? $prompt->variables : [] as $row) {
            if (is_array($row) && self::isName((string) ($row['name'] ?? ''))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, string>
     */
    public static function resolveForSite(?Site $site, ?string $postType = 'article'): array
    {
        $postType = trim((string) $postType);
        if ($postType === '') {
            $postType = 'article';
        }

        $promptSettings = app(SeoPromptSettingsService::class);
        $siteContext = app(SiteDomainPromptContextService::class);

        $variables = array_merge(
            $promptSettings->promptVariables($postType),
            $siteContext->promptVariablesForSite($site),
        );
        $variables['tone'] = $siteContext->resolveToneForSite($site, $variables['tone'] ?? '');

        return $variables;
    }

    /**
     * @return array<string, string>
     */
    public static function resolveForGlobalSite(?string $postType = null): array
    {
        $siteId = SeoAccessControl::globalSiteId();
        $site = $siteId !== null && $siteId > 0
            ? Site::query()->find($siteId)
            : null;

        if ($postType === null) {
            $postType = 'article';
        }

        return self::resolveForSite($site instanceof Site ? $site : null, $postType);
    }

    /**
     * Ghi đè biến site/settings — dùng khi chạy thử / compile.
     *
     * @param  array<string, string>  $variables
     * @return array<string, string>
     */
    public static function mergeInto(array $variables, ?string $postType = null): array
    {
        if ($postType === null) {
            $postType = trim((string) ($variables[self::POST_TYPE_FIELD] ?? 'article'));
            if ($postType === '') {
                $postType = 'article';
            }
        }

        return array_merge($variables, self::resolveForGlobalSite($postType));
    }
}
