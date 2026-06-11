<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Observers;

use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Services\ArticleTocExtractionService;
use Illuminate\Support\Facades\Log;
use Throwable;

final class SeoArticleObserver
{
    public function __construct(
        private readonly ArticleTocExtractionService $tocExtraction,
    ) {}

    public function saved(SeoArticle $article): void
    {
        if (! $article->wasRecentlyCreated && ! $article->wasChanged('body')) {
            return;
        }

        try {
            $this->tocExtraction->extractForArticle($article);
        } catch (Throwable $e) {
            // Bóc tách TOC là tác vụ phụ — không được làm fail thao tác lưu bài.
            Log::warning('SeoArticleObserver: TOC extraction failed', [
                'article_id' => $article->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
