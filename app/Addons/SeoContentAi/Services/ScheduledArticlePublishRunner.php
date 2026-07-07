<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Models\SeoDatabaseConnection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class ScheduledArticlePublishRunner
{
    public function __construct(
        private readonly SeoDatabaseConnectionService $databaseConnection,
        private readonly WordPressArticleSyncService $wordPressSync,
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
            $this->publishDueArticles($stats);

            return $stats;
        }

        foreach ($connections as $connection) {
            if (! $connection instanceof SeoDatabaseConnection) {
                continue;
            }

            try {
                $this->databaseConnection->bootstrapFromConnection($connection);
                $this->publishDueArticles($stats);
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
    private function publishDueArticles(array &$stats): void
    {
        $this->dueArticles()->each(function (SeoArticle $article) use (&$stats): void {
            $stats['processed']++;

            $result = $this->wordPressSync->publishScheduledArticle($article);
            if ($result['success'] ?? false) {
                $stats['published']++;

                return;
            }

            $stats['failed']++;
            Log::warning('Scheduled article publish failed.', [
                'article_id' => $article->id,
                'wp_post_id' => $article->wp_post_id,
                'message' => (string) ($result['message'] ?? ''),
            ]);
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
