<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\SiteSync\Reconciliation;

use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Models\SiteSync\SeoArticleScoreSource;
use App\Models\Site;

final class ArticleScoreSourceReconciler
{
    /**
     * Store scores per source — never merge into a fake single plugin score.
     *
     * @param  list<array<string, mixed>>  $scores
     * @return array{upserted: int}
     */
    public function reconcile(Site $site, array $scores): array
    {
        $upserted = 0;
        $siteId = (int) $site->id;

        foreach ($scores as $row) {
            $source = trim((string) ($row['source'] ?? ''));
            $wpId = (int) ($row['wordpress_id'] ?? 0);
            if ($source === '' || $wpId <= 0) {
                continue;
            }

            $articleId = SeoArticle::query()
                ->where('site_id', $siteId)
                ->where('wp_post_id', $wpId)
                ->value('id');

            SeoArticleScoreSource::query()->updateOrCreate(
                [
                    'site_id' => $siteId,
                    'wordpress_id' => $wpId,
                    'source' => $source,
                ],
                [
                    'article_id' => $articleId !== null ? (int) $articleId : null,
                    'score' => isset($row['score']) && is_numeric($row['score']) ? (float) $row['score'] : null,
                    'raw' => is_array($row['raw'] ?? null) ? $row['raw'] : $row,
                ],
            );
            $upserted++;
        }

        return ['upserted' => $upserted];
    }
}
