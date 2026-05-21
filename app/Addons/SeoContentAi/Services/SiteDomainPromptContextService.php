<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Models\Site;

final class SiteDomainPromptContextService
{
    public const META_KEY = 'seo_domain_prompt_context';

    public const MAX_SHORT_DESCRIPTION_WORDS = 300;

    /**
     * @return array{
     *     short_description: string,
     *     cta: list<array{type: string, value: string}>,
     *     links: list<array{keyword: string, link: string}>,
     * }
     */
    public function getForSite(Site|int $site): array
    {
        $site = $site instanceof Site ? $site : Site::query()->findOrFail((int) $site);
        $site->loadMissing('metas');

        $raw = $site->getMeta(self::META_KEY);
        if (! is_string($raw) || trim($raw) === '') {
            return $this->emptyPayload();
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return $this->emptyPayload();
        }

        $cta = [];
        if (is_array($decoded['cta'] ?? null)) {
            foreach ($decoded['cta'] as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $type = trim((string) ($row['type'] ?? ''));
                $value = trim((string) ($row['value'] ?? ''));
                if ($type === '' && $value === '') {
                    continue;
                }
                $cta[] = ['type' => $type, 'value' => $value];
            }
        }

        $links = [];
        if (is_array($decoded['links'] ?? null)) {
            foreach ($decoded['links'] as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $keyword = trim((string) ($row['keyword'] ?? ''));
                $link = trim((string) ($row['link'] ?? ''));
                if ($keyword === '' && $link === '') {
                    continue;
                }
                $links[] = ['keyword' => $keyword, 'link' => $link];
            }
        }

        return [
            'short_description' => trim((string) ($decoded['short_description'] ?? '')),
            'cta' => $cta,
            'links' => $links,
        ];
    }

    /**
     * @param  array{
     *     short_description?: string,
     *     cta?: list<array{type?: string, value?: string}>,
     *     links?: list<array{keyword?: string, link?: string}>,
     * }  $payload
     */
    public function saveForSite(Site|int $site, array $payload): void
    {
        $site = $site instanceof Site ? $site : Site::query()->findOrFail((int) $site);

        $shortDescription = trim((string) ($payload['short_description'] ?? ''));
        if ($this->countWords($shortDescription) > self::MAX_SHORT_DESCRIPTION_WORDS) {
            throw new \InvalidArgumentException(
                'Mô tả ngắn tối đa ' . self::MAX_SHORT_DESCRIPTION_WORDS . ' từ (hiện tại: '
                . $this->countWords($shortDescription) . ').',
            );
        }

        $cta = [];
        foreach ($payload['cta'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $type = trim((string) ($row['type'] ?? ''));
            $value = trim((string) ($row['value'] ?? ''));
            if ($type === '' || $value === '') {
                continue;
            }
            $cta[] = ['type' => $type, 'value' => $value];
        }

        $links = [];
        foreach ($payload['links'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $keyword = trim((string) ($row['keyword'] ?? ''));
            $link = trim((string) ($row['link'] ?? ''));
            if ($keyword === '' || $link === '') {
                continue;
            }
            $links[] = ['keyword' => $keyword, 'link' => $link];
        }

        $json = json_encode([
            'short_description' => $shortDescription,
            'cta' => $cta,
            'links' => $links,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $site->metas()->updateOrCreate(
            ['meta_key' => self::META_KEY],
            ['meta_value' => $json],
        );
    }

    /**
     * @return array<string, string> Biến gợi ý cho prompt (site_domain, site_short_description, site_cta)
     */
    public function promptVariablesForSite(?Site $site): array
    {
        if ($site === null) {
            return [
                'site_domain' => '',
                'site_short_description' => '',
                'site_cta' => '',
                'site_links' => '',
            ];
        }

        $payload = $this->getForSite($site);

        return [
            'site_domain' => trim((string) $site->domain),
            'site_short_description' => $payload['short_description'],
            'site_cta' => $this->formatCtaForPrompt($payload['cta']),
            'site_links' => $this->formatLinksForPrompt($payload['links']),
        ];
    }

    /**
     * @param  list<array{type: string, value: string}>  $items
     */
    public function formatCtaForPrompt(array $items): string
    {
        if ($items === []) {
            return '';
        }

        $lines = [];
        foreach ($items as $item) {
            $type = trim((string) ($item['type'] ?? ''));
            $value = trim((string) ($item['value'] ?? ''));
            if ($value === '') {
                continue;
            }
            $lines[] = $type !== '' ? "{$type}: {$value}" : $value;
        }

        return implode("\n", $lines);
    }

    /**
     * @param  list<array{keyword: string, link: string}>  $items
     */
    public function formatLinksForPrompt(array $items): string
    {
        if ($items === []) {
            return '';
        }

        $lines = [];
        foreach ($items as $item) {
            $keyword = trim((string) ($item['keyword'] ?? ''));
            $link = trim((string) ($item['link'] ?? ''));
            if ($keyword === '' || $link === '') {
                continue;
            }
            $lines[] = "{$keyword} → {$link}";
        }

        return implode("\n", $lines);
    }

    public function countWords(string $text): int
    {
        $text = trim($text);
        if ($text === '') {
            return 0;
        }

        $parts = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);

        return is_array($parts) ? count($parts) : 0;
    }

    /**
     * @return array<string, string>
     */
    public static function ctaTypeOptions(): array
    {
        return [
            'phone' => 'Số điện thoại',
            'hotline' => 'Hotline',
            'email' => 'Email',
            'address' => 'Địa chỉ',
            'zalo' => 'Zalo',
            'website' => 'Website',
            'facebook' => 'Facebook',
            'working_hours' => 'Giờ làm việc',
            'other' => 'Khác',
        ];
    }

    /**
     * @return array{
     *     short_description: string,
     *     cta: list<array{type: string, value: string}>,
     *     links: list<array{keyword: string, link: string}>,
     * }
     */
    private function emptyPayload(): array
    {
        return [
            'short_description' => '',
            'cta' => [],
            'links' => [],
        ];
    }
}
