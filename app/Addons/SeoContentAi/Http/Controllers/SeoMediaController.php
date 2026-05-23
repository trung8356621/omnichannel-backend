<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Http\Controllers;

use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Models\SeoMedia;
use App\Addons\SeoContentAi\Services\SeoMediaStorageService;
use App\Http\Controllers\Controller;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SeoMediaController extends Controller
{
    public function __construct(
        private readonly SeoMediaStorageService $storage,
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
