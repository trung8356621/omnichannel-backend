<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Support;

use App\Addons\SeoContentAi\Enums\SeoLinkMapType;
use App\Addons\SeoContentAi\Models\SeoArticle;

final class SeoLinkMapLinkTypeClassifier
{
    public static function forManagedArticle(int $sourceSiteId, SeoArticle $targetArticle): SeoLinkMapType
    {
        return (int) ($targetArticle->site_id ?? 0) === $sourceSiteId
            ? SeoLinkMapType::Internal
            : SeoLinkMapType::External;
    }

    public static function forUnresolvedUrl(string $absoluteUrl): SeoLinkMapType
    {
        $host = self::resolveHost($absoluteUrl);

        return self::isWikiTrustHost($host)
            ? SeoLinkMapType::WikiTrust
            : SeoLinkMapType::External;
    }

    public static function isWikiTrustHost(string $host): bool
    {
        if ($host === '') {
            return false;
        }

        return str_contains($host, 'wikipedia.org')
            || str_ends_with($host, '.gov')
            || str_ends_with($host, '.edu');
    }

    public static function resolveHost(string $href): string
    {
        $href = trim($href);
        if ($href === '') {
            return '';
        }

        if (str_starts_with($href, '//')) {
            $href = 'https:'.$href;
        }

        $host = parse_url($href, PHP_URL_HOST);

        return is_string($host) ? self::normalizeDomainHost($host) : '';
    }

    public static function normalizeDomainHost(string $domain): string
    {
        $domain = trim(strtolower($domain));
        $domain = preg_replace('#^https?://#', '', $domain) ?? $domain;
        $domain = rtrim($domain, '/');

        if (str_starts_with($domain, 'www.')) {
            $domain = substr($domain, 4);
        }

        return $domain;
    }
}
