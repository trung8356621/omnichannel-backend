<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Console;

use App\Addons\SeoContentAi\Services\ScheduledArticlePublishRunner;
use App\Support\RuntimeLogger;
use Illuminate\Console\Command;
use Throwable;

/**
 * Canonical Laravel-scheduler entry for Content Project publishing dispatch.
 *
 * Delegates to ContentProjectPublishingQueueRunner (scheduled_publish_at on tasks).
 * Also covers legacy non-project articles.status=scheduled via business event emit.
 * Does not publish WordPress directly.
 */
final class PublishScheduledArticlesCommand extends Command
{
    protected $signature = 'seo:publish-scheduled-articles';

    protected $description = 'Dispatch due Content Project publish queue (+ legacy non-project scheduled articles). No direct WP publish.';

    public function handle(ScheduledArticlePublishRunner $runner): int
    {
        try {
            $stats = $runner->run();
        } catch (Throwable $e) {
            $this->error(sprintf(
                '[seo:publish-scheduled-articles] %s: %s in %s:%d',
                $e::class,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
            ));

            RuntimeLogger::report($e, [
                'command' => 'seo:publish-scheduled-articles',
            ]);

            return self::FAILURE;
        }

        $this->line(sprintf(
            'processed=%d published=%d failed=%d skipped=%d',
            $stats['processed'],
            $stats['published'],
            $stats['failed'],
            $stats['skipped'] ?? 0,
        ));

        return self::SUCCESS;
    }
}
