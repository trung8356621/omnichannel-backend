<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Http\Controllers;

use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Models\SeoMedia;
use App\Addons\SeoContentAi\Services\ArticleEditorMediaAiService;
use App\Addons\SeoContentAi\Services\SeoImageSplitterService;
use App\Addons\SeoContentAi\Services\SeoMediaImageEditorResolverService;
use App\Addons\SeoContentAi\Services\SeoMediaLibraryImageActionService;
use App\Addons\SeoContentAi\Services\SeoMediaStorageService;
use App\Addons\SeoContentAi\Services\SeoWpMediaEditedPendingService;
use App\Http\Controllers\Controller;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SeoMediaController extends Controller
{
    public function __construct(
        private readonly SeoMediaStorageService $storage,
        private readonly SeoMediaImageEditorResolverService $imageEditorResolver,
        private readonly SeoMediaLibraryImageActionService $imageActions,
        private readonly SeoImageSplitterService $imageSplitter,
    ) {}

    public function upload(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'image' => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:5120',
            'site_id' => 'nullable|integer',
            'article_id' => 'nullable|integer',
            'source' => 'nullable|string|max:50',
        ]);

        $siteId = isset($validated['site_id']) ? (int) $validated['site_id'] : null;
        $articleId = isset($validated['article_id']) ? (int) $validated['article_id'] : null;

        if ($articleId !== null) {
            $article = SeoArticle::query()->findOrFail($articleId);
            abort_unless($this->canAccessArticle($article), 403);
            $siteId = (int) $article->site_id;
        } elseif ($siteId !== null) {
            abort_unless($this->canAccessSite($siteId), 403);
        } else {
            abort_unless(auth()->check(), 403);
        }

        $seoMedia = $this->storage->storeUpload(
            $request->file('image'),
            $siteId,
            $articleId,
            (string) ($validated['source'] ?? 'clipboard'),
        );

        return response()->json([
            'success' => true,
            'id' => $seoMedia->id,
            'url' => $seoMedia->publicUrl(),
            'slug' => $seoMedia->slug,
            'alt_text' => $seoMedia->alt_text,
        ]);
    }

    public function importFromUrl(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'url' => ['required', 'string', 'max:2048', 'url'],
            'site_id' => 'nullable|integer',
            'article_id' => 'nullable|integer',
        ]);

        $siteId = isset($validated['site_id']) ? (int) $validated['site_id'] : null;
        $articleId = isset($validated['article_id']) ? (int) $validated['article_id'] : null;

        if ($articleId !== null) {
            $article = SeoArticle::query()->findOrFail($articleId);
            abort_unless($this->canAccessArticle($article), 403);
            $siteId = (int) $article->site_id;
        } elseif ($siteId !== null) {
            abort_unless($this->canAccessSite($siteId), 403);
        } else {
            abort_unless(auth()->check(), 403);
        }

        try {
            $seoMedia = $this->storage->storeFromRemoteUrl(
                (string) $validated['url'],
                $siteId,
                $articleId,
            );
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['url' => $e->getMessage()]);
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'id' => $seoMedia->id,
            'url' => $seoMedia->publicUrl(),
            'slug' => $seoMedia->slug,
            'alt_text' => $seoMedia->alt_text,
            'message' => 'Đã tải và tối ưu ảnh vào thư viện.',
        ]);
    }

    public function rename(Request $request, SeoMedia $media): JsonResponse
    {
        abort_unless($this->canAccessMedia($media), 403);

        $validated = $request->validate([
            'new_slug' => ['required', 'string', 'regex:/^[a-z0-9\-]+$/i', 'max:200'],
        ]);

        try {
            $media = $this->storage->renameBySlug($media, (string) $validated['new_slug']);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['new_slug' => $e->getMessage()]);
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['new_slug' => $e->getMessage()]);
        }

        return response()->json([
            'success' => true,
            'url' => $media->publicUrl(),
            'slug' => $media->slug,
            'id' => (int) $media->id,
        ]);
    }

    public function renameByUrl(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'url' => ['required', 'string', 'max:2048'],
            'new_slug' => ['required', 'string', 'regex:/^[a-z0-9\-]+$/i', 'max:200'],
        ]);

        $url = trim((string) $validated['url']);
        $path = $url;
        if (Str::startsWith($url, ['http://', 'https://'])) {
            $parsed = parse_url($url, PHP_URL_PATH);
            $path = is_string($parsed) ? $parsed : '';
        }

        $path = trim($path);
        if (! Str::startsWith($path, '/storage/')) {
            throw ValidationException::withMessages(['url' => 'URL ảnh không hợp lệ (cần /storage/...).']);
        }

        $relativePath = ltrim(Str::after($path, '/storage/'), '/');
        if ($relativePath === '') {
            throw ValidationException::withMessages(['url' => 'Không xác định được đường dẫn ảnh.']);
        }

        $media = SeoMedia::query()->where('path', $relativePath)->first();
        if (! $media instanceof SeoMedia) {
            // Legacy URL cũ có thể chứa random folder: uploads/seo_media/<rand>/<file>.
            // Ưu tiên map về path phẳng hiện tại uploads/seo_media/<file>.
            $filename = basename($relativePath);
            if ($filename !== '' && $filename !== '.' && $filename !== '..') {
                $flatCandidate = 'uploads/seo_media/' . $filename;
                $media = SeoMedia::query()->where('path', $flatCandidate)->first();
            }
        }

        if (! $media instanceof SeoMedia) {
            // Fallback cuối: tìm theo filename (không tuyệt đối), ưu tiên bản mới nhất.
            $filename = basename($relativePath);
            if ($filename !== '' && $filename !== '.' && $filename !== '..') {
                $media = SeoMedia::query()
                    ->where('filename', $filename)
                    ->orderByDesc('id')
                    ->first();
            }
        }

        if (! $media instanceof SeoMedia) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy ảnh nội bộ theo URL.',
            ], 404);
        }

        abort_unless($this->canAccessMedia($media), 403);

        try {
            $media = $this->storage->renameBySlug($media, (string) $validated['new_slug']);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['new_slug' => $e->getMessage()]);
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['new_slug' => $e->getMessage()]);
        }

        return response()->json([
            'success' => true,
            'id' => (int) $media->id,
            'url' => $media->publicUrl(),
            'slug' => $media->slug,
        ]);
    }

    /**
     * Chuẩn bị URL trình chỉnh sửa (ảnh WP → staging trên Laravel nếu cần).
     */
    public function prepareEditor(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'site_id' => 'required|integer',
            'seo_media_id' => 'nullable|integer',
            'wp_attachment_id' => 'nullable|integer',
            'url' => 'nullable|string|max:2048',
            'slug' => 'nullable|string|max:200',
        ]);

        $siteId = (int) $validated['site_id'];
        abort_unless($this->canAccessSite($siteId), 403);

        $site = Site::query()->findOrFail($siteId);

        try {
            $resolved = $this->imageEditorResolver->resolve($site, [
                'seo_media_id' => (int) ($validated['seo_media_id'] ?? 0),
                'wp_attachment_id' => (int) ($validated['wp_attachment_id'] ?? 0),
                'url' => (string) ($validated['url'] ?? ''),
                'slug' => (string) ($validated['slug'] ?? ''),
                'kind' => (int) ($validated['wp_attachment_id'] ?? 0) > 0 ? 'wordpress' : 'local',
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage() ?: 'Không mở được trình chỉnh sửa.',
            ], 500);
        }

        return response()->json([
            'success' => true,
            'seo_media_id' => $resolved['seo_media_id'],
            'editor_url' => $resolved['editor_url'],
        ]);
    }

    /**
     * Tải metadata + URL ảnh nguồn cho trang tách lưới (Laravel seo_media hoặc WP).
     */
    public function splitterSource(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'site_id' => 'nullable|integer',
            'seo_media_id' => 'nullable|integer',
            'wp_attachment_id' => 'nullable|integer',
            'slug' => 'nullable|string|max:200',
        ]);

        $siteId = isset($validated['site_id']) ? (int) $validated['site_id'] : null;
        if ($siteId !== null && $siteId > 0) {
            abort_unless($this->canAccessSite($siteId), 403);
        }

        try {
            $resolved = $this->imageSplitter->resolveSource(
                ($siteId ?? 0) > 0 ? $siteId : null,
                isset($validated['seo_media_id']) ? (int) $validated['seo_media_id'] : null,
                isset($validated['wp_attachment_id']) ? (int) $validated['wp_attachment_id'] : null,
                (string) ($validated['slug'] ?? ''),
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            ...$resolved,
        ]);
    }

    /**
     * Lưu các mảnh ảnh sau split vào thư viện và xóa ảnh gốc trên Laravel.
     */
    public function saveSplit(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'site_id' => 'required|integer',
            'article_id' => 'nullable|integer',
            'original_seo_media_id' => 'nullable|integer',
            'pieces' => 'required|array|min:1',
            'pieces.*' => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:10240',
        ]);

        $siteId = (int) $validated['site_id'];
        abort_unless($this->canAccessSite($siteId), 403);

        $site = Site::query()->findOrFail($siteId);

        $articleId = isset($validated['article_id']) ? (int) $validated['article_id'] : null;
        if ($articleId !== null && $articleId > 0) {
            $article = SeoArticle::query()->findOrFail($articleId);
            abort_unless($this->canAccessArticle($article), 403);
        }

        /** @var list<\Illuminate\Http\UploadedFile> $pieceFiles */
        $pieceFiles = $request->file('pieces', []);
        if (! is_array($pieceFiles)) {
            $pieceFiles = [];
        }

        try {
            $result = $this->imageSplitter->savePiecesAndDeleteOriginal(
                $site,
                array_values($pieceFiles),
                $articleId,
                isset($validated['original_seo_media_id']) ? (int) $validated['original_seo_media_id'] : null,
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json($result);
    }

    /**
     * Áp dụng đóng dấu cho một ảnh (WordPress hoặc nội bộ).
     */
    public function applyWatermark(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'site_id' => 'required|integer',
            'seo_media_id' => 'nullable|integer',
            'wp_attachment_id' => 'nullable|integer',
            'url' => 'nullable|string|max:2048',
            'slug' => 'nullable|string|max:200',
        ]);

        $siteId = (int) $validated['site_id'];
        abort_unless($this->canAccessSite($siteId), 403);

        $site = Site::query()->findOrFail($siteId);

        $imageRow = [
            'seo_media_id' => (int) ($validated['seo_media_id'] ?? 0),
            'wp_attachment_id' => (int) ($validated['wp_attachment_id'] ?? 0),
            'url' => (string) ($validated['url'] ?? ''),
            'slug' => (string) ($validated['slug'] ?? ''),
            'kind' => (int) ($validated['wp_attachment_id'] ?? 0) > 0 ? 'wordpress' : 'local',
        ];

        $result = $this->imageActions->applyWatermark($site, $imageRow);

        return response()->json([
            'success' => (bool) ($result['success'] ?? false),
            'message' => (string) ($result['message'] ?? ''),
            'url' => (string) ($result['url'] ?? $imageRow['url']),
            'can_restore' => (bool) ($result['can_restore'] ?? false),
            'can_optimize' => (bool) ($result['can_optimize'] ?? false),
        ], ($result['success'] ?? false) ? 200 : 422);
    }

    /**
     * Lưu ảnh sau khi người dùng edit/tô màu từ Editor (client-side).
     */
    public function saveEditedImage(Request $request, SeoMedia $media): JsonResponse
    {
        abort_unless($this->canAccessMedia($media), 403);

        $request->validate([
            'image' => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:10240',
        ]);

        $path = ltrim(str_replace('\\', '/', (string) $media->path), '/');
        if ($path === '') {
            return response()->json([
                'success' => false,
                'message' => 'Ảnh không có đường dẫn lưu trữ trên server.',
            ], 422);
        }

        $editedFile = $request->file('image');
        Storage::disk('public')->put(
            $path,
            file_get_contents($editedFile->getRealPath()),
        );

        if ((int) ($media->wp_attachment_id ?? 0) > 0 && (int) ($media->site_id ?? 0) > 0) {
            app(SeoWpMediaEditedPendingService::class)->recordPendingEdit($media);
            $media->update(['wp_synced_at' => null]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Lưu ảnh thành công',
            'url' => $media->fresh()->publicUrl() . '?t=' . time(),
        ]);
    }

    public function status(SeoMedia $media): JsonResponse
    {
        abort_unless($this->canAccessMedia($media), 403);

        return response()->json([
            'success' => true,
            ...$this->formatAiMediaPayload($media),
        ]);
    }

    public function articleAiJobs(SeoArticle $article): JsonResponse
    {
        abort_unless($this->canAccessArticle($article), 403);

        app(ArticleEditorMediaAiService::class)->reconcileStaleAiMediaJobs((int) $article->id);

        $items = SeoMedia::query()
            ->where('article_id', (int) $article->id)
            ->whereIn('source', ['ai_prompt', 'ai_video_prompt'])
            ->whereIn('status', ['processing', 'failed'])
            ->orderByDesc('id')
            ->get()
            ->map(fn (SeoMedia $media): array => $this->formatAiMediaPayload($media))
            ->values()
            ->all();

        return response()->json([
            'success' => true,
            'items' => $items,
        ]);
    }

    public function retryGeneration(SeoMedia $media): JsonResponse
    {
        abort_unless($this->canAccessMedia($media), 403);

        $validated = request()->validate([
            'retry_input' => 'nullable|string|max:8000',
        ]);

        try {
            $media = app(ArticleEditorMediaAiService::class)->retryGeneration(
                $media,
                isset($validated['retry_input']) ? (string) $validated['retry_input'] : null,
            );
        } catch (\InvalidArgumentException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Đã đưa job vào hàng đợi lại.',
            ...$this->formatAiMediaPayload($media),
        ]);
    }

    public function deleteAiJob(SeoMedia $media): JsonResponse
    {
        abort_unless($this->canAccessMedia($media), 403);

        if (! $media->isAiGenerationJob()) {
            return response()->json([
                'success' => false,
                'message' => __('seo-content-ai::common.ai_job_delete_only'),
            ], 422);
        }

        $path = ltrim(str_replace('\\', '/', (string) ($media->path ?? '')), '/');
        $isSharedPlaceholder = $path === SeoMedia::placeholderLoadingPath();
        $isUploadedFile = str_starts_with($path, 'uploads/seo_media/');

        if (! $isSharedPlaceholder && $isUploadedFile && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }

        $media->delete();

        return response()->json([
            'success' => true,
            'message' => __('seo-content-ai::common.ai_job_deleted'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatAiMediaPayload(SeoMedia $media): array
    {
        $status = (string) ($media->status ?? 'completed');
        $url = (string) ($media->url ?? '');

        if (str_contains($url, 'placeholder-loading')) {
            $url = SeoMedia::placeholderLoadingUrl();
        }

        if ($url === '') {
            $url = $media->publicUrl();
        }

        return [
            'id' => (int) $media->id,
            'status' => $status,
            'url' => $url,
            'error_message' => $media->error_message,
            'source' => (string) ($media->source ?? ''),
            'media_type' => $media->aiToolType(),
            'editor_block_id' => (string) ($media->editor_block_id ?? ''),
            'slug' => (string) ($media->slug ?? ''),
            'retry_input' => $this->extractRetryInput($media),
            'created_at' => $media->created_at?->toIso8601String(),
            'is_placeholder' => $status === 'processing' || str_contains($url, 'placeholder-loading'),
        ];
    }

    private function extractRetryInput(SeoMedia $media): string
    {
        $variables = is_array($media->prompt_variables) ? $media->prompt_variables : [];
        if ($variables === []) {
            return '';
        }

        $preferredKeys = ['prompt', 'input', 'content', 'text', 'description', 'image_prompt'];
        foreach ($preferredKeys as $key) {
            $value = trim((string) ($variables[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        foreach ($variables as $value) {
            $value = trim((string) $value);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function canAccessMedia(SeoMedia $media): bool
    {
        if ($media->article_id !== null) {
            $article = SeoArticle::query()->find($media->article_id);

            return $article !== null && $this->canAccessArticle($article);
        }

        if ($media->site_id !== null) {
            return $this->canAccessSite((int) $media->site_id);
        }

        return auth()->check();
    }

    private function canAccessArticle(SeoArticle $article): bool
    {
        $user = auth()->user();
        if ($user === null) {
            return false;
        }

        if ($user->role === 'admin') {
            return true;
        }

        return Site::query()
            ->whereKey($article->site_id)
            ->where('user_id', $user->id)
            ->exists();
    }

    private function canAccessSite(int $siteId): bool
    {
        $user = auth()->user();
        if ($user === null) {
            return false;
        }

        if ($user->role === 'admin') {
            return true;
        }

        return Site::query()
            ->whereKey($siteId)
            ->where('user_id', $user->id)
            ->exists();
    }
}
