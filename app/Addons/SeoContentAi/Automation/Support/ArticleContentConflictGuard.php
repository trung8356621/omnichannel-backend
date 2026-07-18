<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Automation\Support;

use App\Addons\SeoContentAi\Automation\Data\ActionResult;
use App\Addons\SeoContentAi\Models\SeoArticle;
use Carbon\Carbon;

/**
 * Concurrency guard cho article.content.update.
 * Domain chưa có revision column trên articles → dùng expected_updated_at / expected_content_hash.
 */
final class ArticleContentConflictGuard
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function assertCompatible(SeoArticle $article, array $input): ?ActionResult
    {
        $expectedUpdatedAt = $input['expected_updated_at'] ?? null;
        $expectedHash = $input['expected_content_hash'] ?? null;

        if ($expectedUpdatedAt === null && ($expectedHash === null || $expectedHash === '')) {
            return null;
        }

        if ($expectedUpdatedAt !== null && $expectedUpdatedAt !== '') {
            $actual = $article->updated_at;
            if ($actual === null) {
                return ActionResult::failure(
                    'conflict_updated_at',
                    'Article updated_at missing; cannot verify expected_updated_at.',
                    error: ['expected_updated_at' => (string) $expectedUpdatedAt],
                );
            }

            try {
                $expected = Carbon::parse((string) $expectedUpdatedAt);
            } catch (\Throwable) {
                return ActionResult::failure(
                    'invalid_expected_updated_at',
                    'expected_updated_at is not a valid datetime.',
                );
            }

            if ($actual->getTimestamp() !== $expected->getTimestamp()) {
                return ActionResult::failure(
                    'conflict_updated_at',
                    'Article was modified by another writer (updated_at mismatch).',
                    error: [
                        'expected_updated_at' => $expected->toIso8601String(),
                        'actual_updated_at' => $actual->toIso8601String(),
                    ],
                );
            }
        }

        if (is_string($expectedHash) && $expectedHash !== '') {
            $actualHash = $this->contentHash((string) ($article->body ?? ''));
            if (! hash_equals($expectedHash, $actualHash)) {
                return ActionResult::failure(
                    'conflict_content_hash',
                    'Article body hash mismatch; refusing silent overwrite.',
                    error: [
                        'expected_content_hash' => $expectedHash,
                        'actual_content_hash' => $actualHash,
                    ],
                );
            }
        }

        return null;
    }

    public function contentHash(string $body): string
    {
        return hash('sha256', trim($body));
    }
}
