<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Automation\BusinessHook\Enums\BusinessEventName;
use App\Addons\SeoContentAi\Automation\BusinessHook\Support\BusinessHookEmitter;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Models\SeoDatabaseConnection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Cron due-scheduled articles: emit business event only — never direct WordPress mutate.
 */
final class ScheduledArticlePublishRunner
{
    public function __construct(
        private readonly SeoDatabaseConnectionService $databaseConnection,
        private readonly BusinessHookEmitter $emitter,
    ) {}

    /**
     * @return array{processed: int, published: int, failed: int}
     */
    public function run(): array
    {
        $stats = [
            'processed' => 0,
            'published' => 0,
            'failed' => 0,
        ];

        if (! Schema::hasTable('seo_database_connections')) {
            return $stats;
        }

        $connections = SeoDatabaseConnection::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        if ($connections->isEmpty()) {
            $this->databaseConnection->bootstrapLegacySharedConnection();
            $this->dispatchDueArticles($stats);

            return $stats;
        }

        foreach ($connections as $connection) {
            if (! $connection instanceof SeoDatabaseConnection) {
                continue;
            }

            try {
                $this->databaseConnection->bootstrapFromConnection($connection);
                $this->dispatchDueArticles($stats);
            } catch (Throwable $exception) {
                Log::warning('Scheduled article publish: connection bootstrap failed.', [
                    'connection_id' => $connection->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return $stats;
    }

    /**
     * @param  array{processed: int, published: int, failed: int}  $stats
     */
    private function dispatchDueArticles(array &$stats): void
    {
        $this->dueArticles()->each(function (SeoArticle $article) use (&$stats): void {
            $stats['processed']++;

            try {
                $this->emitter->emit(BusinessEventName::ArticlePublishRequested, $article, [
                    'article_id' => (int) $article->id,
                    'site_id' => (int) ($article->site_id ?? 0) ?: null,
                    'wp_post_id' => (int) ($article->wp_post_id ?? 0) ?: null,
                    'status' => 'publish_requested',
                    'source' => 'scheduled_article_publish_runner',
                ]);
                $stats['published']++;
            } catch (Throwable $e) {
                $stats['failed']++;
                Log::warning('Scheduled article publish event emit failed.', [
                    'article_id' => $article->id,
                    'message' => $e->getMessage(),
                ]);
            }
        });
    }

    /**
     * @return Collection<int, SeoArticle>
     */
    private function dueArticles(): Collection
    {
        return SeoArticle::query()
            ->where('status', 'scheduled')
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->where('wp_post_id', '>', 0)
            ->orderBy('published_at')
            ->orderBy('id')
            ->limit(50)
            ->get();
    }
}
