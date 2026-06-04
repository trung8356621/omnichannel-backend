<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Support\CtaLinkFormatter;
use App\Models\Site;

/**
 * Placeholder [phone], [website], … trong nội dung bài — AI dùng thay vì tự đặt tên random.
 */
final class ArticleCtaPlaceholderService
{
    public const BLANK_PLACEHOLDER_CLASS = 'seo-cta-blank-placeholder';

    /** @var array<string, string> */
    public const PLACEHOLDER_TYPES = [
        'phone' => 'Số điện thoại',
        'hotline' => 'Hotline',
        'email' => 'Email',
        'zalo' => 'Zalo',
        'address' => 'Địa chỉ',
        'website' => 'Website',
        'facebook' => 'Facebook',
        'working_hours' => 'Giờ làm việc',
    ];

    public function __construct(
        private readonly SiteDomainPromptContextService $promptContext,
    ) {}

    public function placeholderGuideForPrompt(): string
    {
        $lines = [
            'Khi cần chèn thông tin liên hệ / CTA trong bài, BẮT BUỘC dùng placeholder sau (viết đúng cú pháp, không tự đặt tên khác như [Website/Hotline], [Liên hệ], …):',
        ];

        foreach (self::PLACEHOLDER_TYPES as $type => $label) {
            $suffix = CtaLinkFormatter::isPlainTextType($type) ? ' (text thuần, không link)' : '';
            $lines[] = "  [{$type}] — {$label}{$suffix}";
        }

        $lines[] = 'Ví dụ: «Liên hệ tư vấn ngay tại [phone] hoặc [website] để nhận báo giá tốt nhất!»';

        return implode("\n", $lines);
    }

    /**
     * @return array<string, string>
     */
    public function resolveValuesForSite(Site|int|null $site): array
    {
        if ($site === null) {
            return [];
        }

        $values = [];

        foreach ($this->promptContext->getForSite($site)['cta'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $type = mb_strtolower(trim((string) ($row['type'] ?? '')));
            $value = trim((string) ($row['value'] ?? ''));

            if ($type === '' || $value === '' || isset($values[$type])) {
                continue;
            }

            $values[$type] = $value;
        }

        if (! isset($values['hotline']) && isset($values['phone'])) {
            $values['hotline'] = $values['phone'];
        }

        if (! isset($values['phone']) && isset($values['hotline'])) {
            $values['phone'] = $values['hotline'];
        }

        return $values;
    }

    /**
     * Tìm các placeholder CTA ([phone], [address], …) đang được dùng trong nội dung.
     *
     * @return list<string>
     */
    public function detectPlaceholderTypes(string ...$contents): array
    {
        $found = [];

        foreach ($contents as $content) {
            if ($content === '') {
                continue;
            }

            foreach (array_keys(self::PLACEHOLDER_TYPES) as $type) {
                if (isset($found[$type])) {
                    continue;
                }

                if (preg_match('/\[' . preg_quote($type, '/') . '\]/iu', $content) === 1) {
                    $found[$type] = true;
                }
            }
        }

        return array_keys($found);
    }

    /**
     * Xử lý CTA khi đăng / convert bài:
     *  - Placeholder có giá trị trên domain → thay bằng giá trị thật (link/text).
     *  - Placeholder CHƯA có giá trị → tự thêm "biến trắng" vào CTA domain (để điền sau),
     *    placeholder được giữ nguyên cho tới khi có giá trị.
     *
     * @param  list<array<string, mixed>>  $faqs
     * @return array{html: string, faqs: list<array<string, mixed>>, added_blank_types: list<string>}
     */
    public function applyForPublish(Site|int|null $site, string $html, array $faqs = []): array
    {
        if ($site === null) {
            return ['html' => $html, 'faqs' => $faqs, 'added_blank_types' => []];
        }

        $faqText = [];
        foreach ($faqs as $faq) {
            if (! is_array($faq)) {
                continue;
            }
            foreach (['question', 'answer', 'more'] as $field) {
                $faqText[] = (string) ($faq[$field] ?? '');
            }
        }

        $usedTypes = $this->detectPlaceholderTypes($html, ...$faqText);

        $added = [];
        if ($usedTypes !== []) {
            $values = $this->resolveValuesForSite($site);

            $missing = [];
            foreach ($usedTypes as $type) {
                if (trim((string) ($values[$type] ?? '')) === '') {
                    $missing[] = $type;
                }
            }

            if ($missing !== []) {
                $added = $this->promptContext->addBlankCtaTypes($site, $missing);
            }
        }

        $html = $this->replaceInHtml($html, $site);
        $faqs = $this->replaceInFaqs($faqs, $site);

        return [
            'html' => $this->highlightBlankPlaceholdersInHtml($html, $site),
            'faqs' => $this->highlightBlankPlaceholdersInFaqs($faqs, $site),
            'added_blank_types' => $added,
        ];
    }

    /**
     * Bọc [phone], [website], … chưa có giá trị domain bằng span đỏ trong editor.
     */
    public function highlightBlankPlaceholdersInHtml(string $html, Site|int|null $site): string
    {
        if (trim($html) === '' || $site === null) {
            return $html;
        }

        $html = $this->stripBlankPlaceholderMarkup($html);
        $values = $this->resolveValuesForSite($site);

        foreach (array_keys(self::PLACEHOLDER_TYPES) as $type) {
            if (trim((string) ($values[$type] ?? '')) !== '') {
                continue;
            }

            $pattern = '/\[' . preg_quote($type, '/') . '\]/iu';
            $replacement = sprintf(
                '<span class="%s" data-cta-type="%s">[%s]</span>',
                self::BLANK_PLACEHOLDER_CLASS,
                htmlspecialchars($type, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                htmlspecialchars($type, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            );

            $html = (string) preg_replace($pattern, $replacement, $html);
        }

        return $html;
    }

    /**
     * Gỡ span highlight editor → giữ [type] thuần (lưu DB / đồng bộ WP).
     */
    public function stripBlankPlaceholderMarkup(string $html): string
    {
        if ($html === '' || ! str_contains($html, self::BLANK_PLACEHOLDER_CLASS)) {
            return $html;
        }

        return (string) preg_replace(
            '/<span\s+class="' . preg_quote(self::BLANK_PLACEHOLDER_CLASS, '/')
            . '"[^>]*data-cta-type="([a-z_]+)"[^>]*>\[\1\]<\/span>/iu',
            '[$1]',
            $html,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $faqs
     * @return list<array<string, mixed>>
     */
    public function highlightBlankPlaceholdersInFaqs(array $faqs, Site|int|null $site): array
    {
        if ($faqs === [] || $site === null) {
            return $faqs;
        }

        foreach ($faqs as $index => $faq) {
            if (! is_array($faq)) {
                continue;
            }

            foreach (['question', 'answer', 'more'] as $field) {
                if (! isset($faq[$field]) || ! is_string($faq[$field])) {
                    continue;
                }

                $faqs[$index][$field] = $this->highlightBlankPlaceholdersInHtml($faq[$field], $site);
            }
        }

        return $faqs;
    }

    public function replaceInHtml(string $html, Site|int|null $site): string
    {
        if (trim($html) === '' || $site === null) {
            return $html;
        }

        $values = $this->resolveValuesForSite($site);

        foreach (array_keys(self::PLACEHOLDER_TYPES) as $type) {
            $value = trim((string) ($values[$type] ?? ''));
            if ($value === '') {
                continue;
            }

            $pattern = '/\[' . preg_quote($type, '/') . '\]/iu';
            $replacement = $this->buildReplacement($type, $value);

            $html = (string) preg_replace($pattern, $replacement, $html);
        }

        return $html;
    }

    /**
     * @param  list<array<string, mixed>>  $faqs
     * @return list<array<string, mixed>>
     */
    public function replaceInFaqs(array $faqs, Site|int|null $site): array
    {
        if ($faqs === [] || $site === null) {
            return $faqs;
        }

        foreach ($faqs as $index => $faq) {
            if (! is_array($faq)) {
                continue;
            }

            foreach (['question', 'answer', 'more'] as $field) {
                if (! isset($faq[$field]) || ! is_string($faq[$field])) {
                    continue;
                }

                $faqs[$index][$field] = $this->replaceInHtml($faq[$field], $site);
            }
        }

        return $faqs;
    }

    private function buildReplacement(string $type, string $value): string
    {
        if (! $this->shouldRenderAsLink($type)) {
            return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        $href = CtaLinkFormatter::format($type, $value);
        if ($href === '') {
            return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return sprintf(
            '<a href="%s">%s</a>',
            htmlspecialchars($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        );
    }

    private function shouldRenderAsLink(string $type): bool
    {
        if (CtaLinkFormatter::isPlainTextType($type)) {
            return false;
        }

        return in_array($type, ['phone', 'hotline', 'email', 'zalo', 'website', 'facebook'], true);
    }
}
