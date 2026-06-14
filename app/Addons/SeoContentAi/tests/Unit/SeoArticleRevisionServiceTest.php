<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Models\SeoArticleRevision;
use App\Addons\SeoContentAi\Services\SeoArticleRevisionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

final class SeoArticleRevisionServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['omi_seo_ai'];

    public function test_capture_after_save_prunes_old_revisions(): void
    {
        $article = SeoArticle::query()->create([
            'site_id' => 1,
            'title' => 'Revision test',
            'body' => '<p>Initial</p>',
            'status' => 'draft',
            'type' => 'article',
        ]);

        $service = app(SeoArticleRevisionService::class);

        for ($index = 1; $index <= 16; $index++) {
            $service->captureAfterSave(
                $article,
                'Title '.$index,
                '<p>Body '.$index.'</p>',
                ['seo_title' => 'SEO '.$index],
                1,
            );
        }

        $this->assertSame(15, $service->countForArticle((int) $article->id));

        $latest = SeoArticleRevision::query()
            ->where('article_id', $article->id)
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($latest);
        $this->assertSame('Title 16', $latest->title);
        $this->assertSame('<p>Body 16</p>', $latest->content);
    }
}
