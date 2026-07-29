<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Automation\BusinessHook\Enums\BusinessEventName;
use App\Addons\SeoContentAi\Automation\BusinessHook\Support\BusinessHookEmitter;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Services\ContentProject\ContentProjectPublishingQueueRunner;
use App\Models\SeoDatabaseConnection;
use App\Support\RuntimeLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Cron due-scheduled articles: emit business event only — never direct WordPress mutate.
 * Content Project items dùng scheduled_publish_at (SaaS queue), không WP future/cron.
 */
final class ScheduledArticlePublishRunner
{
    public function __construct(
        private readonly SeoDatabaseConnectionService $databaseConnection,
        private readonly BusinessHookEmitter $emitter,
        private readonly ContentProjectPublishingQueueRunner $contentProjectQueue,
    ) {}

    /**
     * @return array{processed: int, published: int, failed: int, skipped: int}
     */
    public function run(): array
    {
        $stats = [
            'processed' => 0,
            'published' => 0,
            'failed' => 0,
            'skipped' => 0,
        ];

        try {
            if (! Schema::hasTable('seo_database_connections')) {
                return $stats;
            }
        } catch (Throwable $e) {
            RuntimeLogger::warning('Scheduled article publish: cannot inspect seo_database_connections.', [
                'error' => $e->getMessage(),
                'exception' => $e::class,
            ]);

            throw $e;
        }

        $connections = SeoDatabaseConnection::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        if ($connections->isEmpty()) {
            try {
                $this->databaseConnection->bootstrapLegacySharedConnection();
                $this->dispatchDueArticles($stats);
            } catch (Throwable $exception) {
                RuntimeLogger::warning('Scheduled article publish: legacy connection path failed.', [
                    'error' => $exception->getMessage(),
                    'exception' => $exception::class,
                    'file' => $exception->getFile(),
                    'line' => $exception->getLine(),
                ]);

                throw $exception;
            }

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
                $stats['failed']++;
                RuntimeLogger::warning('Scheduled article publish: connection bootstrap failed.', [
                    'connection_id' => $connection->id,
                    'error' => $exception->getMessage(),
                    'exception' => $exception::class,
                ]);
            }
        }

        return $stats;
    }

    /**
     * @param  array{processed: int, published: int, failed: int, skipped: int}  $stats
     */
    private function dispatchDueArticles(array &$stats): void
    {
        $projectStats = $this->contentProjectQueue->dispatchDue();
        $stats['processed'] += $projectStats['processed'];
        $stats['published'] += $projectStats['published'];
        $stats['failed'] += $projectStats['failed'];
        $stats['skipped'] += $projectStats['skipped'] ?? 0;

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
                RuntimeLogger::warning('Scheduled article publish event emit failed.', [
                    'article_id' => $article->id,
                    'message' => $e->getMessage(),
                ]);
            }
        });
    }

    /**
     * Legacy/non-project schedule: articles.status=scheduled + published_at.
     * Bỏ qua bài đang thuộc active Content Project (chúng đi qua scheduled_publish_at trên task).
     *
     * @return Collection<int, SeoArticle>
     */
    private function dueArticles(): Collection
    {
        return SeoArticle::query()
            ->where('status', 'scheduled')
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->where('wp_post_id', '>', 0)
            ->whereDoesntHave('projectTasks', static function ($query): void {
                $query->whereNull('archived_at')
                    ->whereHas('project', static function ($projectQuery): void {
                        $projectQuery->whereNull('archived_at');
                    });
            })
            ->orderBy('published_at')
            ->orderBy('id')
            ->limit(50)
            ->get();
    }
}
