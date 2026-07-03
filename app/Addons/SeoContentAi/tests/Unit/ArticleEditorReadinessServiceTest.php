<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Services\ArticleEditorReadinessService;
use PHPUnit\Framework\TestCase;

final class ArticleEditorReadinessServiceTest extends TestCase
{
    public function test_body_hash_is_stable_for_same_content(): void
    {
        $service = new ArticleEditorReadinessService;
        $article = new \App\Addons\SeoContentAi\Models\SeoArticle([
            'body' => '<p>Hello world</p>',
        ]);

        self::assertSame(
            $service->bodyHash($article),
            $service->bodyHash($article),
        );
    }
}
