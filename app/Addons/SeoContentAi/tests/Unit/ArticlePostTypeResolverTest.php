<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Models\ArticleMeta;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Models\SeoProjectTask;
use App\Addons\SeoContentAi\Support\ArticlePostTypeResolver;
use Illuminate\Database\Eloquent\Collection;
use PHPUnit\Framework\TestCase;

final class ArticlePostTypeResolverTest extends TestCase
{
    public function test_local_article_type_wins_over_stale_wp_product_meta(): void
    {
        $article = new SeoArticle;
        $article->type = SeoProjectTask::POST_TYPE_ARTICLE;
        $article->setRelation('articleMetas', new Collection([
            new ArticleMeta([
                'meta_key' => 'wp_post_type',
                'meta_value' => 'product',
            ]),
        ]));

        self::assertSame(
            SeoProjectTask::POST_TYPE_ARTICLE,
            ArticlePostTypeResolver::resolve($article),
        );
    }

    public function test_local_product_type_is_respected(): void
    {
        $article = new SeoArticle;
        $article->type = SeoProjectTask::POST_TYPE_PRODUCT;
        $article->setRelation('articleMetas', new Collection([
            new ArticleMeta([
                'meta_key' => 'wp_post_type',
                'meta_value' => 'post',
            ]),
        ]));

        self::assertSame(
            SeoProjectTask::POST_TYPE_PRODUCT,
            ArticlePostTypeResolver::resolve($article),
        );
    }

    public function test_wp_post_type_used_when_local_type_empty(): void
    {
        $article = new SeoArticle;
        $article->type = '';
        $article->setRelation('articleMetas', new Collection([
            new ArticleMeta([
                'meta_key' => 'wp_post_type',
                'meta_value' => 'product',
            ]),
        ]));

        self::assertSame(
            SeoProjectTask::POST_TYPE_PRODUCT,
            ArticlePostTypeResolver::resolve($article),
        );
    }
}
