<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Models\SeoArticle;
use Illuminate\Support\Carbon;

/**
 * Phân biệt manual save / sync save / system update trên articles.
 * Không đụng updated_at — field đó bị automation/status/archive làm nhiễu.
 */
final class ArticleLastSavedTimestampService
{
    /** Origin ActionContext được phép ghi last_manual_saved_at. */
    private const MANUAL_SAVE_ORIGINS = [
        'article_editor',
    ];

    public function touchManualSaved(SeoArticle $article): void
    {
        $article->forceFill(['last_manual_saved_at' => now()])->saveQuietly();
    }

    public function touchSynced(SeoArticle $article): void
    {
        $article->forceFill(['last_synced_at' => now()])->saveQuietly();
    }

    public function shouldTouchManualFromOrigin(?string $origin): bool
    {
        return in_array(trim((string) $origin), self::MANUAL_SAVE_ORIGINS, true);
    }

    /**
     * @return array{
     *     at: Carbon|null,
     *     source: 'manual'|'sync'|null,
     *     display: string,
     *     source_label: string|null
     * }
     */
    public function resolve(SeoArticle|array|null $article): array
    {
        $manual = $this->asCarbon(
            is_array($article)
                ? ($article['last_manual_saved_at'] ?? null)
                : ($article?->last_manual_saved_at)
        );
        $synced = $this->asCarbon(
            is_array($article)
                ? ($article['last_synced_at'] ?? null)
                : ($article?->last_synced_at)
        );

        if ($manual === null && $synced === null) {
            return [
                'at' => null,
                'source' => null,
                'display' => '—',
                'source_label' => null,
            ];
        }

        if ($manual !== null && ($synced === null || $manual->gte($synced))) {
            return [
                'at' => $manual,
                'source' => 'manual',
                'display' => $manual->timezone((string) config('app.timezone', 'UTC'))->format('d/m/Y H:i'),
                'source_label' => 'Lưu thủ công',
            ];
        }

        return [
            'at' => $synced,
            'source' => 'sync',
            'display' => $synced->timezone((string) config('app.timezone', 'UTC'))->format('d/m/Y H:i'),
            'source_label' => 'Đồng bộ',
        ];
    }

    private function asCarbon(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value);
        }

        if (is_string($value) && trim($value) !== '') {
            try {
                return Carbon::parse($value);
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }
}
