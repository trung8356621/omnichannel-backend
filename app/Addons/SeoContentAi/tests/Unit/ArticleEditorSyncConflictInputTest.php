<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Http\Controllers\ArticleEditorSyncController;
use App\Addons\SeoContentAi\Http\Requests\ArticleEditorActionRequest;
use App\Addons\SeoContentAi\Models\SeoArticle;
use Illuminate\Support\Facades\Validator;
use ReflectionMethod;
use Tests\TestCase;

final class ArticleEditorSyncConflictInputTest extends TestCase
{
    public function test_build_content_update_input_includes_expected_conflict_fields(): void
    {
        $controller = app(ArticleEditorSyncController::class);
        $method = new ReflectionMethod($controller, 'buildContentUpdateInput');
        $method->setAccessible(true);

        $article = new SeoArticle(['id' => 42]);
        $bundle = [
            'html' => '<p>Body</p>',
            'expected_updated_at' => '2026-07-01T10:00:00+00:00',
            'expected_content_hash' => 'abc123deadbeef',
            'article_meta' => ['title' => 'Title'],
        ];

        $input = $method->invoke($controller, $article, $bundle, '<p>Body</p>');

        self::assertSame(42, $input['article_id'] ?? null);
        self::assertSame('<p>Body</p>', $input['content'] ?? null);
        self::assertSame('2026-07-01T10:00:00+00:00', $input['expected_updated_at'] ?? null);
        self::assertSame('abc123deadbeef', $input['expected_content_hash'] ?? null);
        self::assertSame('Title', $input['title'] ?? null);
    }

    public function test_action_request_allows_expected_conflict_fields(): void
    {
        $request = new ArticleEditorActionRequest;
        $validator = Validator::make([
            'html' => '<p>x</p>',
            'expected_updated_at' => '2026-07-01T10:00:00+00:00',
            'expected_content_hash' => hash('sha256', 'x'),
        ], $request->rules());

        self::assertFalse($validator->fails());
    }
}
