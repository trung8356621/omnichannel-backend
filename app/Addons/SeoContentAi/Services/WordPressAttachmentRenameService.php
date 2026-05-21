<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Models\Site;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

final class WordPressAttachmentRenameService
{
    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array{success: bool, message: string, renamed_count?: int, posts_updated?: int, renamed?: array<int, mixed>, errors?: array<int, mixed>}
     */
    public function renameBatch(SeoArticle $article, array $items): array
    {
        $normalized = $this->normalizeItems($items);
        if ($normalized === []) {
            return [
                'success' => false,
                'message' => 'Không có ảnh WordPress hợp lệ để đổi tên.',
            ];
        }

        $article->loadMissing('site');
        $site = $article->site;
        if (! $site instanceof Site) {
            return [
                'success' => false,
                'message' => 'Bài viết chưa gắn domain.',
            ];
        }

        $site->loadMissing('metas');
        $writeToken = trim((string) ($site->getMeta('seo_migration_token') ?? ''));
        if ($writeToken === '') {
            return [
                'success' => false,
                'message' => 'Thiếu Migration/Write token trên domain.',
            ];
        }

        $url = $this->buildRenameUrl($site);
        if ($url === '') {
            return [
                'success' => false,
                'message' => 'Không xác định được URL WordPress.',
            ];
        }

        try {
            $response = Http::timeout(120)
                ->acceptJson()
                ->withToken($writeToken)
                ->post($url, ['items' => $normalized]);

            if (! $response->successful()) {
                $message = (string) ($response->json('message') ?? $response->body());

                return [
                    'success' => false,
                    'message' => 'WordPress trả lỗi HTTP ' . $response->status() . ': ' . mb_substr($message, 0, 400),
                ];
            }

            $payload = $response->json();
            if (! is_array($payload)) {
                return [
                    'success' => false,
                    'message' => 'Phản hồi WordPress không hợp lệ.',
                ];
            }

            $renamedCount = (int) ($payload['renamed_count'] ?? 0);
            $postsUpdated = (int) ($payload['posts_updated'] ?? 0);
            $errorCount = (int) ($payload['error_count'] ?? 0);

            if ($renamedCount === 0 && $errorCount > 0) {
                $firstError = is_array($payload['errors'][0] ?? null)
                    ? (string) ($payload['errors'][0]['message'] ?? 'Không đổi tên được.')
                    : 'Không đổi tên được.';

                return [
                    'success' => false,
                    'message' => $firstError,
                    'errors' => is_array($payload['errors'] ?? null) ? $payload['errors'] : [],
                ];
            }

            return [
                'success' => true,
                'message' => sprintf(
                    'Đã đổi tên %d ảnh trên WordPress · cập nhật URL trong %d bài/trang.',
                    $renamedCount,
                    $postsUpdated,
                ),
                'renamed_count' => $renamedCount,
                'posts_updated' => $postsUpdated,
                'renamed' => is_array($payload['renamed'] ?? null) ? $payload['renamed'] : [],
                'errors' => is_array($payload['errors'] ?? null) ? $payload['errors'] : [],
            ];
        } catch (Throwable $e) {
            Log::error('WordPress attachment rename failed', [
                'article_id' => $article->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Không kết nối được WordPress: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array{attachment_id: int, new_slug: string, old_url: string}>
     */
    private function normalizeItems(array $items): array
    {
        $normalized = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $attachmentId = (int) ($item['attachment_id'] ?? $item['wp_attachment_id'] ?? 0);
            $newSlug = trim((string) ($item['new_slug'] ?? $item['slug'] ?? ''));
            $oldUrl = trim((string) ($item['old_url'] ?? $item['old_src'] ?? $item['src'] ?? ''));

            if ($attachmentId <= 0 || $newSlug === '') {
                continue;
            }

            $normalized[] = [
                'attachment_id' => $attachmentId,
                'new_slug' => $newSlug,
                'old_url' => $oldUrl,
            ];
        }

        return $normalized;
    }

    private function buildRenameUrl(Site $site): string
    {
        $base = app(WordPressArticleContentService::class)->getPermalinkBase($site);
        if ($base === '') {
            return '';
        }

        return $base . '/wp-json/omi-seo-ai/v1/attachments/rename';
    }
}
