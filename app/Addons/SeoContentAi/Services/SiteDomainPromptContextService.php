<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Models\Site;

final class SiteDomainPromptContextService
{
    public const META_KEY = 'seo_domain_prompt_context';

    public const MAX_SHORT_DESCRIPTION_WORDS = 300;

    /** @var list<string> */
    public const PHONE_SLOT_TYPES = ['phone_1', 'phone_2', 'phone_3'];

    /** @var list<string> */
    public const EMAIL_SLOT_TYPES = ['email_1', 'email_2', 'email_3'];

    /**
     * @return list<string>
     */
    public static function contactSlotTypes(): array
    {
        return [...self::PHONE_SLOT_TYPES, ...self::EMAIL_SLOT_TYPES];
    }

    /**
     * @return list<string>
     */
    public static function reservedCtaTypes(): array
    {
        return [
            'phone',
            ...self::PHONE_SLOT_TYPES,
            'email',
            ...self::EMAIL_SLOT_TYPES,
            'website',
        ];
    }

    public static function isGlobalOnlyCtaType(string $type): bool
    {
        $type = mb_strtolower(trim($type));

        return in_array($type, self::globalOnlyCtaTypes(), true);
    }

    /**
     * @return list<string>
     */
    public static function globalOnlyCtaTypes(): array
    {
        return array_values(array_diff(
            array_keys(self::globalCtaFormTypeOptions()),
            array_keys(self::ctaFormTypeOptions()),
        ));
    }

    /** @var array<string, mixed>|null */
    private ?array $testSitePayload = null;

    public const DEFAULT_CTA_INTRO = 'Tạo một bảng so sánh hoặc danh sách liệt kê (bullet points) để tăng khả năng đạt Featured Snippet. Kêu gọi hành động (CTA) ở mỗi heading — dùng placeholder [phone], [website], [email], … (xem danh sách bên dưới), không tự đặt tên khác.';

    /**
     * @param  array{
     *     tone?: string,
     *     short_description?: string,
     *     cta_intro?: string,
     *     cta?: list<array{type?: string, value?: string}>,
     *     links?: list<array{keyword?: string, link?: string}>,
     * }  $payload
     */
    public static function withTestPayload(array $payload): self
    {
        $service = new self;
        $service->testSitePayload = [
            'tone' => trim((string) ($payload['tone'] ?? '')),
            'short_description' => trim((string) ($payload['short_description'] ?? '')),
            'cta_intro' => trim((string) ($payload['cta_intro'] ?? '')),
            'cta' => is_array($payload['cta'] ?? null) ? $payload['cta'] : [],
            'links' => is_array($payload['links'] ?? null) ? $payload['links'] : [],
        ];

        return $service;
    }

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
        if ($this->testSitePayload !== null) {
            return $this->testSitePayload;
        }

        $payload = $this->getRawPayloadForSite($site);
        $payload['cta'] = $this->mergeGlobalCtaIntoRows($payload['cta']);

        return $payload;
    }

    /**
     * Payload domain thuần từ meta (không gộp CTA global).
     *
     * @return array{
     *     tone: string,
     *     short_description: string,
     *     cta_intro: string,
     *     cta: list<array{type: string, value: string}>,
     *     links: list<array{keyword: string, link: string}>,
     * }
     */
    public function getRawPayloadForSite(Site|int $site): array
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

        $cta = $this->normalizeCtaRows($cta);

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
            : '';

        if ($ctaIntro === '') {
            $ctaIntro = app(SeoDomainCtaGlobalSettingsService::class)->getDefaultCtaIntro();
        }

        return [
            'tone' => trim((string) ($decoded['tone'] ?? '')),
            'short_description' => trim((string) ($decoded['short_description'] ?? '')),
            'cta_intro' => $ctaIntro,
            'cta' => $cta,
            'links' => $links,
        ];
    }

    /**
     * Gộp CTA global (vd. working_hours) vào danh sách domain — domain không cần cấu hình lẻ.
     *
     * @param  list<array{type?: string, value?: string}>  $domainCta
     * @param  list<array{type?: string, value?: string}>|null  $globalCta
     * @return list<array{type: string, value: string}>
     */
    public function mergeGlobalCtaIntoRows(array $domainCta, ?array $globalCta = null): array
    {
        $merged = $this->normalizeCtaRows($domainCta);
        $existingTypes = [];

        foreach ($merged as $row) {
            $type = mb_strtolower(trim((string) ($row['type'] ?? '')));
            $value = trim((string) ($row['value'] ?? ''));

            if ($type !== '' && $value !== '') {
                $existingTypes[$type] = true;
            }
        }

        $globalCta ??= app(SeoDomainCtaGlobalSettingsService::class)->getGlobalCta();

        foreach ($globalCta as $row) {
            if (! is_array($row)) {
                continue;
            }

            $type = mb_strtolower(trim((string) ($row['type'] ?? '')));
            $value = trim((string) ($row['value'] ?? ''));

            if ($type === '' || $value === '' || isset($existingTypes[$type])) {
                continue;
            }

            $merged[] = ['type' => $type, 'value' => $value];
            $existingTypes[$type] = true;
        }

        return $merged;
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
     * Dữ liệu hiển thị trên bảng danh sách domain.
     *
     * @return array{tone: string, short_description: string, cta_shortcodes: list<string>}
     */
    public function tableSummaryForSite(Site $site): array
    {
        $payload = $this->getForSite($site);
        $shortcodes = ['[website]'];

        if ($this->hasAnyPhoneSlot($payload['cta'])) {
            $shortcodes[] = '[phone]';
        }

        if ($this->hasAnyEmailSlot($payload['cta'])) {
            $shortcodes[] = '[email]';
        }

        foreach ($payload['cta'] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $type = mb_strtolower(trim((string) ($row['type'] ?? '')));
            if ($type === '' || in_array($type, self::reservedCtaTypes(), true)) {
                continue;
            }

            $shortcodes[] = '['.$type.']';
        }

        return [
            'tone' => trim((string) ($payload['tone'] ?? '')),
            'short_description' => trim((string) ($payload['short_description'] ?? '')),
            'cta_shortcodes' => array_values(array_unique($shortcodes)),
        ];
    }

    /**
     * @param  list<array{type?: string, value?: string}>  $cta
     */
    public function hasAnyPhoneSlot(array $cta): bool
    {
        foreach (self::PHONE_SLOT_TYPES as $slot) {
            if ($this->ctaValueFromRows($cta, $slot) !== '') {
                return true;
            }
        }

        return $this->ctaValueFromRows($cta, 'phone') !== '';
    }

    /**
     * @param  list<array{type?: string, value?: string}>  $cta
     */
    public function hasAnyEmailSlot(array $cta): bool
    {
        foreach (self::EMAIL_SLOT_TYPES as $slot) {
            if ($this->ctaValueFromRows($cta, $slot) !== '') {
                return true;
            }
        }

        return $this->ctaValueFromRows($cta, 'email') !== '';
    }

    /**
     * @param  list<array{type?: string, value?: string}>  $cta
     */
    public function ctaValueFromRows(array $cta, string $type): string
    {
        $type = mb_strtolower(trim($type));

        foreach ($cta as $row) {
            if (! is_array($row)) {
                continue;
            }

            if (mb_strtolower(trim((string) ($row['type'] ?? ''))) !== $type) {
                continue;
            }

            return trim((string) ($row['value'] ?? ''));
        }

        return '';
    }

    /**
     * @param  list<array{type?: string, value?: string}>  $cta
     * @return list<array{type: string, value: string}>
     */
    public function normalizeCtaRows(array $cta): array
    {
        $normalized = [];
        $legacyPhone = '';
        $legacyEmail = '';

        foreach ($cta as $row) {
            if (! is_array($row)) {
                continue;
            }

            $type = mb_strtolower(trim((string) ($row['type'] ?? '')));
            $value = trim((string) ($row['value'] ?? ''));

            if ($type === '' && $value === '') {
                continue;
            }

            if ($type === 'phone') {
                if ($legacyPhone === '' && $value !== '') {
                    $legacyPhone = $value;
                }

                continue;
            }

            if ($type === 'email') {
                if ($legacyEmail === '' && $value !== '') {
                    $legacyEmail = $value;
                }

                continue;
            }

            if ($type === 'website') {
                continue;
            }

            if (self::isGlobalOnlyCtaType($type)) {
                continue;
            }

            $normalized[] = ['type' => $type, 'value' => $value];
        }

        if ($legacyPhone !== '' && $this->ctaValueFromRows($normalized, 'phone_1') === '') {
            array_unshift($normalized, ['type' => 'phone_1', 'value' => $legacyPhone]);
        }

        if ($legacyEmail !== '' && $this->ctaValueFromRows($normalized, 'email_1') === '') {
            array_unshift($normalized, ['type' => 'email_1', 'value' => $legacyEmail]);
        }

        return $normalized;
    }

    /**
     * @param  array<string, string>  $slots
     * @param  list<array{type?: string, value?: string}>  $cta
     * @return list<array{type: string, value: string}>
     */
    public function mergeContactSlotsIntoCta(array $slots, array $cta): array
    {
        $cta = $this->normalizeCtaRows($cta);

        $withoutDedicated = array_values(array_filter(
            $cta,
            static fn (array $row): bool => ! in_array(
                mb_strtolower(trim((string) ($row['type'] ?? ''))),
                self::reservedCtaTypes(),
                true,
            ),
        ));

        $merged = [];

        foreach (self::contactSlotTypes() as $slot) {
            $value = trim((string) ($slots[$slot] ?? ''));
            if ($value === '') {
                continue;
            }

            $merged[] = ['type' => $slot, 'value' => $value];
        }

        return [...$merged, ...$withoutDedicated];
    }

    public function resolveEffectiveCtaIntro(string $domainCtaIntro): string
    {
        $domainCtaIntro = trim($domainCtaIntro);

        return $domainCtaIntro !== ''
            ? $domainCtaIntro
            : app(SeoDomainCtaGlobalSettingsService::class)->getDefaultCtaIntro();
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
                'Mô tả ngắn tối đa '.self::MAX_SHORT_DESCRIPTION_WORDS.' từ (hiện tại: '
                .$this->countWords($shortDescription).').',
            );
        }

        $contactSlots = [
            'phone_1' => trim((string) ($payload['phone_1'] ?? '')),
            'phone_2' => trim((string) ($payload['phone_2'] ?? '')),
            'phone_3' => trim((string) ($payload['phone_3'] ?? '')),
            'email_1' => trim((string) ($payload['email_1'] ?? '')),
            'email_2' => trim((string) ($payload['email_2'] ?? '')),
            'email_3' => trim((string) ($payload['email_3'] ?? '')),
        ];

        $cta = $this->mergeContactSlotsIntoCta($contactSlots, $payload['cta'] ?? []);

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
     * Thêm các loại CTA còn thiếu vào cấu hình domain dưới dạng "biến trắng" (value rỗng),
     * để người dùng điền sau. Giữ nguyên các CTA đã có. Trả về danh sách type vừa thêm.
     *
     * @param  list<string>  $types
     * @return list<string>
     */
    public function addBlankCtaTypes(Site|int $site, array $types): array
    {
        $types = array_values(array_unique(array_filter(array_map(
            static fn ($type): string => mb_strtolower(trim((string) $type), 'UTF-8'),
            $types,
        ))));

        if ($types === []) {
            return [];
        }

        $site = $site instanceof Site ? $site : Site::query()->find((int) $site);
        if ($site === null) {
            return [];
        }

        $payload = $this->getRawPayloadForSite($site);

        $existing = [];
        foreach ($payload['cta'] as $row) {
            $existing[mb_strtolower(trim((string) ($row['type'] ?? '')), 'UTF-8')] = true;
        }

        $globalSettings = app(SeoDomainCtaGlobalSettingsService::class);

        $added = [];
        foreach ($types as $type) {
            if ($type === '' || isset($existing[$type])) {
                continue;
            }

            if (self::isGlobalOnlyCtaType($type)) {
                if ($globalSettings->globalCtaValue($type) !== '') {
                    continue;
                }

                continue;
            }

            if ($type === 'phone') {
                foreach (self::PHONE_SLOT_TYPES as $slot) {
                    if (isset($existing[$slot])) {
                        continue;
                    }

                    $payload['cta'][] = ['type' => $slot, 'value' => ''];
                    $existing[$slot] = true;
                    $added[] = $slot;
                }

                continue;
            }

            if ($type === 'email') {
                foreach (self::EMAIL_SLOT_TYPES as $slot) {
                    if (isset($existing[$slot])) {
                        continue;
                    }

                    $payload['cta'][] = ['type' => $slot, 'value' => ''];
                    $existing[$slot] = true;
                    $added[] = $slot;
                }

                continue;
            }

            $payload['cta'][] = ['type' => $type, 'value' => ''];
            $existing[$type] = true;
            $added[] = $type;
        }

        if ($added === []) {
            return [];
        }

        $this->writePayload($site, $payload);

        return $added;
    }

    /**
     * Ghi payload xuống meta domain, GIỮ LẠI cả CTA có value rỗng (biến trắng để điền sau).
     *
     * @param  array{
     *     tone?: string,
     *     short_description?: string,
     *     cta_intro?: string,
     *     cta?: list<array{type?: string, value?: string}>,
     *     links?: list<array{keyword?: string, link?: string}>,
     * }  $payload
     */
    private function writePayload(Site $site, array $payload): void
    {
        $cta = [];
        foreach ($payload['cta'] ?? [] as $row) {
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

        $links = [];
        foreach ($payload['links'] ?? [] as $row) {
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

        $json = json_encode([
            'tone' => trim((string) ($payload['tone'] ?? '')),
            'short_description' => trim((string) ($payload['short_description'] ?? '')),
            'cta_intro' => trim((string) ($payload['cta_intro'] ?? '')),
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
            'site_cta' => $this->formatCtaForPrompt(
                $payload['cta'],
                $this->resolveEffectiveCtaIntro((string) ($payload['cta_intro'] ?? '')),
                $site,
            ),
            // Link list không đưa vào prompt (tiết kiệm token); dùng DomainLinkListKeywordSyncService + gợi ý editor.
            'site_links' => '',
        ];
    }

    /**
     * @param  list<array{type: string, value: string}>  $items
     */
    public function formatCtaForPrompt(array $items, string $intro = '', Site|int|null $site = null): string
    {
        $intro = trim($intro);
        $guide = app(ArticleCtaPlaceholderService::class)->placeholderGuideForPrompt();

        if ($intro === '') {
            return $guide;
        }

        return $intro."\n\n".$guide;
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
            'zalo' => 'Zalo',
            'address' => 'Address',
            'facebook' => 'Facebook',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function globalCtaFormTypeOptions(): array
    {
        return [
            ...self::ctaFormTypeOptions(),
            'working_hours' => 'Working hours',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function phoneSlotFormLabels(): array
    {
        return [
            'phone_1' => 'Phone 1',
            'phone_2' => 'Phone 2',
            'phone_3' => 'Phone 3',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function emailSlotFormLabels(): array
    {
        return [
            'email_1' => 'Email 1',
            'email_2' => 'Email 2',
            'email_3' => 'Email 3',
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
            'cta_intro' => app(SeoDomainCtaGlobalSettingsService::class)->getDefaultCtaIntro(),
            'cta' => [],
            'links' => [],
        ];
    }
}
