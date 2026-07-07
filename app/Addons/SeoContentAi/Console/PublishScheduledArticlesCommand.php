<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Console;

use App\Addons\SeoContentAi\Services\ScheduledArticlePublishRunner;
use Illuminate\Console\Command;

final class PublishScheduledArticlesCommand extends Command
{
    protected $signature = 'seo:publish-scheduled-articles';

    protected $description = 'Đăng các bài SEO đã đến giờ lên WordPress (Laravel cron, không dùng WP future).';

    public function handle(ScheduledArticlePublishRunner $runner): int
    {
        $stats = $runner->run();

        $this->line(sprintf(
            'processed=%d published=%d failed=%d',
            $stats['processed'],
            $stats['published'],
            $stats['failed'],
        ));

        return self::SUCCESS;
    }
}
