<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Support\CtaLinkFormatter;
use App\Models\Site;

final class DomainCtaEditorService
{
    public function __construct(
        private readonly SiteDomainPromptContextService $promptContext,
    ) {}

    /**
     * @return list<array{type: string, value: string, label: string, href: string, can_insert: bool, is_blank?: bool}>
     */
    public function forSite(Site|int|null $site): array
    {
        if ($site === null) {
            return [];
        }

        $site = $site instanceof Site ? $site : Site::query()->find((int) $site);
        if ($site === null) {
            return [];
        }

        $items = [];

        foreach ($this->promptContext->getForSite($site)['cta'] ?? [] as $row) {
            $type = mb_strtolower(trim((string) ($row['type'] ?? '')));
            $value = trim((string) ($row['value'] ?? ''));
            if ($type === '') {
                continue;
            }

            if ($value === '') {
                $items[] = [
                    'type' => $type,
                    'value' => '',
                    'label' => "[{$type}]",
                    'href' => '',
                    'plain_text' => true,
                    'can_insert' => true,
                    'is_blank' => true,
                ];

                continue;
            }

            $plainText = CtaLinkFormatter::isPlainTextType($type);
            $href = $plainText ? '' : CtaLinkFormatter::format($type, $value);

            $items[] = [
                'type' => $type,
                'value' => $value,
                'label' => $value,
                'href' => $href,
                'plain_text' => $plainText,
                'can_insert' => true,
                'is_blank' => false,
            ];
        }

        return $items;
    }
}
