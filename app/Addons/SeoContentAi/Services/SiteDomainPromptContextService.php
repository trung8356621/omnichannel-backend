<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Models\Site;

final class SiteDomainPromptContextService
{
    public const META_KEY = 'seo_domain_prompt_context';

    public const MAX_SHORT_DESCRIPTION_WORDS = 300;

    public const DEFAULT_CTA_INTRO = 'Tạo một bảng so sánh hoặc danh sách liệt kê (bullet points) để tăng khả năng đạt Featured Snippet. Kêu gọi hành động (CTA) ở mỗi heading — dùng placeholder [phone], [website], [email], … (xem danh sách bên dưới), không tự đặt tên khác.';

    /**
     * @return array{
     *     tone: string,
     *     short_description: string,
     *     cta_intro: string,
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

        $ctaIntro = array_key_exists('cta_intro', $decoded)
            ? trim((string) ($decoded['cta_intro'] ?? ''))
            : self::DEFAULT_CTA_INTRO;

        return [
            'tone' => trim((string) ($decoded['tone'] ?? '')),
            'short_description' => trim((string) ($decoded['short_description'] ?? '')),
            'cta_intro' => $ctaIntro,
            'cta' => $cta,
            'links' => $links,
        ];
    }

    public function resolveToneForSite(?Site $site, string $globalTone): string
    {
        if ($site === null) {
            return $globalTone;
        }

        $domainTone = trim($this->getForSite($site)['tone'] ?? '');

        return $domainTone !== '' ? $domainTone : $globalTone;
    }

    /**
     * @param  array{
     *     tone?: string,
     *     short_description?: string,
     *     cta_intro?: string,
     *     cta?: list<array{type?: string, value?: string}>,
     *     links?: list<array{keyword?: string, link?: string}>,
     * }  $payload
     */
    public function saveForSite(Site|int $site, array $payload): void
    {
        $site = $site instanceof Site ? $site : Site::query()->findOrFail((int) $site);

        $tone = trim((string) ($payload['tone'] ?? ''));
        $shortDescription = trim((string) ($payload['short_description'] ?? ''));
        $ctaIntro = trim((string) ($payload['cta_intro'] ?? ''));
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
            'tone' => $tone,
            'short_description' => $shortDescription,
            'cta_intro' => $ctaIntro,
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
            'site_cta' => $this->formatCtaForPrompt($payload['cta'], $payload['cta_intro'] ?? ''),
            // Link list không đưa vào prompt (tiết kiệm token); dùng DomainLinkListKeywordSyncService + gợi ý editor.
            'site_links' => '',
        ];
    }

    /**
     * @param  list<array{type: string, value: string}>  $items
     */
    public function formatCtaForPrompt(array $items, string $intro = ''): string
    {
        $intro = trim($intro);
        $lines = [];

        foreach ($items as $item) {
            $type = trim((string) ($item['type'] ?? ''));
            $value = trim((string) ($item['value'] ?? ''));
            if ($value === '') {
                continue;
            }
            $lines[] = $type !== '' ? "{$type}: {$value}" : $value;
        }

        $guide = app(ArticleCtaPlaceholderService::class)->placeholderGuideForPrompt();

        $parts = [];
        if ($intro !== '') {
            $parts[] = $intro;
        }
        $parts[] = $guide;
        if ($lines !== []) {
            $parts[] = "Giá trị đã cấu hình trên domain:\n" . implode("\n", $lines);
        }

        return implode("\n\n", $parts);
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
    public static function ctaFormTypeOptions(): array
    {
        return [
            'phone' => 'Phone',
            'email' => 'Email',
            'zalo' => 'Zalo',
            'address' => 'Address',
            'website' => 'Website',
        ];
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
     *     cta_intro: string,
     *     cta: list<array{type: string, value: string}>,
     *     links: list<array{keyword: string, link: string}>,
     * }
     */
    private function emptyPayload(): array
    {
        return [
            'tone' => '',
            'short_description' => '',
            'cta_intro' => self::DEFAULT_CTA_INTRO,
            'cta' => [],
            'links' => [],
        ];
    }
}
