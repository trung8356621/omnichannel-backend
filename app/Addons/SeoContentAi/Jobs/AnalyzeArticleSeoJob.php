<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Jobs;

use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Services\SeoDatabaseConnectionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

final class AnalyzeArticleSeoJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 120;

    /**
     * @param  array<string, mixed>|null  $seoAnalysis
     */
    public function __construct(
        public int $articleId,
        public string $html,
        public ?array $seoAnalysis,
        public string $seoTitle,
        public string $slug,
        public ?string $metaDescription,
    ) {}

    public function handle(
        SeoDatabaseConnectionService $databaseConnection,
        \App\Addons\SeoContentAi\Services\SeoAnalyzerService $analyzer,
    ): void {
        $databaseConnection->bootstrapLegacySharedConnection();

        $article = SeoArticle::query()->find($this->articleId);
        if (! $article instanceof SeoArticle) {
            return;
        }

        if ((int) ($article->site_id ?? 0) > 0) {
            $databaseConnection->bootstrapSeoDatabaseConnection((int) $article->site_id);
            $article = SeoArticle::query()->find($this->articleId);
        }

        if (! $article instanceof SeoArticle) {
            return;
        }

        try {
            if (is_array($this->seoAnalysis) && array_key_exists('score', $this->seoAnalysis)) {
                $analyzer->persistClientAnalysis($article, $this->html, $this->seoAnalysis);
            } else {
                $analyzer->analyzeSubmittedContent(
                    $article,
                    $this->html,
                    $this->seoTitle,
                    $this->slug,
                    $this->metaDescription,
                );
            }
        } catch (Throwable $exception) {
            Log::warning('AnalyzeArticleSeoJob failed', [
                'article_id' => $this->articleId,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
