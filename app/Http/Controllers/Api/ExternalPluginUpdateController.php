<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ExternalPlugin\ExternalPluginRegistry;
use App\Services\ExternalPlugin\WordPressPluginReleaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ExternalPluginUpdateController extends Controller
{
    public function __construct(
        private readonly ExternalPluginRegistry $registry,
    ) {}

    /**
     * GET /api/plugins/{slug}/update-check
     */
    public function checkUpdate(string $slug): JsonResponse
    {
        $releases = $this->releaseService($slug);

        $metadata = $releases->loadMetadata();
        if ($metadata === null) {
            return response()->json(['error' => 'Plugin metadata not found'], 404);
        }

        $version = (string) ($metadata['version'] ?? '');
        if (! $releases->isValidVersion($version)) {
            return response()->json(['error' => 'Invalid version in metadata'], 500);
        }

        if (! $releases->zipExists($version)) {
            return response()->json(['error' => 'Plugin package not found'], 404);
        }

        $metadata['download_url'] = URL::temporarySignedRoute(
            'api.external-plugin.download',
            now()->addHours(24),
            ['slug' => $slug, 'version' => $version],
        );

        return response()->json($metadata);
    }

    /**
     * GET /api/seo/plugin/update-check (backward compat)
     */
    public function legacyCheckUpdate(): JsonResponse
    {
        $slug = 'omi-seo-ai-bridge';
        $releases = $this->releaseService($slug);

        $metadata = $releases->loadMetadata();
        if ($metadata === null) {
            return response()->json(['error' => 'Plugin metadata not found'], 404);
        }

        $version = (string) ($metadata['version'] ?? '');
        if (! $releases->isValidVersion($version)) {
            return response()->json(['error' => 'Invalid version in metadata'], 500);
        }

        if (! $releases->zipExists($version)) {
            return response()->json(['error' => 'Plugin package not found'], 404);
        }

        $metadata['download_url'] = URL::temporarySignedRoute(
            'api.seo.plugin.download',
            now()->addHours(24),
            ['version' => $version],
        );

        return response()->json($metadata);
    }

    /**
     * GET /api/plugins/{slug}/info.json
     */
    public function infoJson(string $slug): JsonResponse
    {
        $metadata = $this->releaseService($slug)->loadMetadata();
        if ($metadata === null) {
            return response()->json(['error' => 'Plugin metadata not found'], 404);
        }

        return response()->json($metadata, 200, [], JSON_UNESCAPED_UNICODE);
    }

    /**
     * GET /api/plugins/{slug}/download/{version}
     */
    public function download(Request $request, string $slug, string $version): BinaryFileResponse|JsonResponse
    {
        if (! $request->hasValidSignature()) {
            return response()->json(['error' => 'Invalid or expired download link'], 403);
        }

        return $this->streamZip($slug, $version);
    }

    /**
     * GET /api/seo/plugin/download/{version} (backward compat)
     */
    public function legacyDownload(Request $request, string $version): BinaryFileResponse|JsonResponse
    {
        if (! $request->hasValidSignature()) {
            return response()->json(['error' => 'Invalid or expired download link'], 403);
        }

        return $this->streamZip('omi-seo-ai-bridge', $version);
    }

    /**
     * GET /seo/wp-plugin/download/{version} (backward compat)
     */
    public function legacyDownloadForPanel(string $version): BinaryFileResponse|JsonResponse
    {
        return $this->streamZip('omi-seo-ai-bridge', $version);
    }

    /**
     * GET /wp-plugin-release/download/{slug}/{version}
     */
    public function downloadForPanel(string $slug, string $version): BinaryFileResponse|JsonResponse
    {
        return $this->streamZip($slug, $version);
    }

    /**
     * GET /storage/plugins/{package_prefix}/info.json
     */
    public function infoJsonByPackagePrefix(string $packagePrefix): JsonResponse
    {
        foreach ($this->registry->all() as $manifest) {
            if ($manifest->packagePrefix === $packagePrefix) {
                return $this->infoJson($manifest->slug);
            }
        }

        return response()->json(['error' => 'Plugin metadata not found'], 404);
    }

    private function streamZip(string $slug, string $version): BinaryFileResponse|JsonResponse
    {
        $releases = $this->releaseService($slug);

        if (! $releases->isValidVersion($version)) {
            return response()->json(['error' => 'Invalid version'], 400);
        }

        $absolutePath = $releases->absoluteZipPath($version);
        if ($absolutePath === null) {
            return response()->json(['error' => 'Requested version file not found on server.'], 404);
        }

        return response()->download($absolutePath, $releases->zipFileName($version), [
            'Content-Type' => 'application/zip',
        ]);
    }

    private function releaseService(string $slug): WordPressPluginReleaseService
    {
        $manifest = $this->registry->resolveOrFail($slug);

        return WordPressPluginReleaseService::forManifest($manifest);
    }
}
