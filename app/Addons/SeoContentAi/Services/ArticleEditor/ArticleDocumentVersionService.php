<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\ArticleEditor;

use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Support\RuntimeLogger;
use Illuminate\Support\Facades\Schema;

/**
 * Canonical document_version authority for articles.body mutations.
 * seo_article_revisions is snapshot history only — not reused as version counter.
 */
final class ArticleDocumentVersionService
{
    public function current(SeoArticle $article): int
    {
        if (! $this->columnExists()) {
            return 1;
        }

        return max(1, (int) ($article->document_version ?? 1));
    }

    /**
     * Increment when body is dirty. Safe to call from model observer or writers.
     */
    public function bumpIfBodyChanging(SeoArticle $article): void
    {
        if (! $this->columnExists()) {
            return;
        }

        if (! $article->isDirty('body') && ! $article->isDirty('editor_document')) {
            return;
        }

        $current = max(1, (int) ($article->getOriginal('document_version') ?? $article->document_version ?? 1));
        $article->document_version = $current + 1;
    }

    public function ensureDefaultOnCreate(SeoArticle $article): void
    {
        if (! $this->columnExists()) {
            return;
        }

        if ($article->document_version === null || (int) $article->document_version < 1) {
            $article->document_version = 1;
        }
    }

    /**
     * Assert expected version matches current (optimistic concurrency).
     *
     * @throws ArticleEditorSessionException
     */
    public function assertExpected(SeoArticle $article, int|string|null $expected): void
    {
        if ($expected === null || $expected === '') {
            return;
        }

        $expectedVersion = (int) $expected;
        $actual = $this->current($article);

        if ($expectedVersion !== $actual) {
            RuntimeLogger::warning('seo.editor.document_version_conflict', [
                'article_id' => (int) $article->getKey(),
                'expected' => $expectedVersion,
                'actual' => $actual,
            ]);

            throw ArticleEditorSessionException::make(
                \App\Addons\SeoContentAi\Support\ArticleEditorSessionErrorCode::DOCUMENT_VERSION_CONFLICT,
                'Document version conflict; refusing overwrite.',
                [
                    'expected_document_version' => $expectedVersion,
                    'actual_document_version' => $actual,
                ],
                409,
            );
        }
    }

    private function columnExists(): bool
    {
        static $exists = null;
        if ($exists !== null) {
            return $exists;
        }

        try {
            $exists = Schema::connection('omi_seo_ai')->hasColumn('articles', 'document_version');
        } catch (\Throwable) {
            $exists = false;
        }

        return $exists;
    }
}
