<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Http\Controllers;

use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Models\SeoMedia;
use App\Addons\SeoContentAi\Services\SeoMediaImageEditorResolverService;
use App\Addons\SeoContentAi\Services\SeoMediaLibraryImageActionService;
use App\Addons\SeoContentAi\Services\SeoMediaStorageService;
use App\Addons\SeoContentAi\Services\SeoWpMediaEditedPendingService;
use App\Http\Controllers\Controller;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class SeoMediaController extends Controller
{
    public function __construct(
        private readonly SeoMediaStorageService $storage,
        private readonly SeoMediaImageEditorResolverService $imageEditorResolver,
        private readonly SeoMediaLibraryImageActionService $imageActions,
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
