<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Support;

use Illuminate\Support\Facades\Log;

/**
 * Lightweight Article Editor mount/SEO bootstrap timing (no content/secrets in logs).
 */
final class ArticleEditorPerfDebug
{
    /** @var array<string, float> */
    private array $starts = [];

    /** @var array<string, float> */
    private array $durationsMs = [];

    private int $wpHttpCount = 0;

    public function enabled(): bool
    {
        return (bool) config('seo-content-ai.article_editor_perf_debug', false);
    }

    public function start(string $label): void
    {
        if (! $this->enabled()) {
            return;
        }

        $this->starts[$label] = microtime(true);
    }

    public function stop(string $label): void
    {
        if (! $this->enabled()) {
            return;
        }

        $started = $this->starts[$label] ?? null;
        if ($started === null) {
            return;
        }

        $this->durationsMs[$label] = round((microtime(true) - $started) * 1000, 2);
        unset($this->starts[$label]);
    }

    public function countWpHttp(int $delta = 1): void
    {
        if (! $this->enabled()) {
            return;
        }

        $this->wpHttpCount += max(0, $delta);
    }

    /**
     * @param  array<string, scalar|null>  $extra
     */
    public function logSummary(string $context, array $extra = []): void
    {
        if (! $this->enabled()) {
            return;
        }

        Log::debug('article_editor_perf', array_merge([
            'context' => $context,
            'durations_ms' => $this->durationsMs,
            'wp_http_count' => $this->wpHttpCount,
        ], $extra));
    }
}
