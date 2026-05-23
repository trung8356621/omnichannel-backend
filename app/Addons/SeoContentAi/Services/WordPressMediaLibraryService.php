<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Models\Site;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final class WordPressMediaLibraryService
{
    public function __construct(
        private readonly WordPressArticleContentService $wpContent,
    ) {
    }

    /**
     * @return array{
     *     images: list<array<string, mixed>>,
     *     total: int,
     *     total_pages: int,
     *     page: int,
     *     error: string|null,
     * }
     */
    public function fetch(
        Site $site,
        ?string $filterMonth = null,
        int $page = 1,
        int $perPage = 50,
        ?string $search = null,
    ): array
    {
        $site->loadMissing('metas');
        $readToken = trim((string) ($site->getMeta('seo_read_token') ?? ''));
        if ($readToken === '') {
            return $this->emptyResult($page, 'Thiếu Read token trên domain. Cấu hình tại Danh sách tên miền.');
        }

        $base = $this->wpContent->getPermalinkBase($site);
        if ($base === '') {
            return $this->emptyResult($page, 'Không xác định được URL WordPress của domain.');
        }

        $filterMonth = trim((string) $filterMonth);
        $query = [
            'per_page' => max(1, min(100, $perPage)),
            'page' => max(1, $page),
            'orderby' => 'date',
            'order' => 'desc',
            'media_type' => 'image',
        ];

        if ($filterMonth !== '') {
            try {
                $date = Carbon::createFromFormat('Y-m', $filterMonth);
            } catch (Throwable) {
                return $this->emptyResult($page, 'Tháng lọc không hợp lệ.');
            }

            $query['after'] = $date->copy()->startOfMonth()->toIso8601String();
            $query['before'] = $date->copy()->endOfMonth()->toIso8601String();
        }

        $search = trim((string) $search);
        if ($search !== '') {
            $query['search'] = $search;
        }

        try {
            $response = Http::timeout(60)
                ->acceptJson()
                ->withToken($readToken)
                ->get($base . '/wp-json/wp/v2/media', $query);

            if (! $response->successful()) {
                $message = (string) ($response->json('message') ?? $response->body());

                return $this->emptyResult($page, 'WordPress trả lỗi HTTP ' . $response->status() . ': ' . mb_substr($message, 0, 300));
            }

            $payload = $response->json();
            if (! is_array($payload)) {
                return $this->emptyResult($page, 'Phản hồi WordPress không hợp lệ.');
            }

            $images = [];
            foreach ($payload as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $id = (int) ($item['id'] ?? 0);
                $url = trim((string) ($item['source_url'] ?? ''));
                if ($id <= 0 || $url === '') {
                    continue;
                }

                $title = $item['title'] ?? '';
                if (is_array($title)) {
                    $title = (string) ($title['rendered'] ?? '');
                }

                $alt = (string) ($item['alt_text'] ?? '');
                if ($alt === '' && is_array($item['meta'] ?? null)) {
                    $alt = (string) ($item['meta']['_wp_attachment_image_alt'] ?? '');
                }

                $images[] = [
                    'kind' => 'wordpress',
                    'id' => $id,
                    'wp_attachment_id' => $id,
                    'seo_media_id' => 0,
                    'url' => $url,
                    'slug' => trim((string) ($item['slug'] ?? '')),
                    'title' => html_entity_decode(strip_tags($title), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                    'alt' => $alt,
                    'date' => (string) ($item['date'] ?? ''),
                ];
            }

            $total = (int) $response->header('X-WP-Total', count($images));
            $totalPages = max(1, (int) $response->header('X-WP-TotalPages', 1));

            return [
                'images' => $images,
                'total' => $total,
                'total_pages' => $totalPages,
                'page' => $page,
                'error' => null,
            ];
        } catch (Throwable $e) {
            Log::warning('WordPress media library fetch failed', [
                'site_id' => $site->id,
                'month' => $filterMonth,
                'error' => $e->getMessage(),
            ]);

            return $this->emptyResult($page, 'Không kết nối được WordPress: ' . $e->getMessage());
        }
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function updateSlug(Site $site, int $attachmentId, string $newSlug, string $oldUrl = ''): array
    {
        $newSlug = Str::slug($newSlug);
        if ($attachmentId <= 0 || $newSlug === '') {
            return [
                'success' => false,
                'message' => 'Attachment ID hoặc slug không hợp lệ.',
            ];
        }

        $result = app(WordPressAttachmentRenameService::class)->renameForSite($site, [
            [
                'attachment_id' => $attachmentId,
                'new_slug' => $newSlug,
                'old_url' => $oldUrl,
            ],
        ]);

        return [
            'success' => (bool) ($result['success'] ?? false),
            'message' => (string) ($result['message'] ?? 'Không đổi tên được.'),
        ];
    }

    /**
     * @return array{images: list<array<string, mixed>>, total: int, total_pages: int, page: int, error: string|null}
     */
    private function emptyResult(int $page, ?string $error): array
    {
        return [
            'images' => [],
            'total' => 0,
            'total_pages' => 1,
            'page' => max(1, $page),
            'error' => $error,
        ];
    }
}
