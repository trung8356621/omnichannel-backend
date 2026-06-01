<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Http\Controllers;

use App\Addons\SeoContentAi\Services\WordPressPluginReleaseService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PluginUpdateController extends Controller
{
    public function __construct(
        private readonly WordPressPluginReleaseService $releases,
    ) {}

    /**
     * GET /api/seo/plugin/update-check
     */
    public function checkUpdate(Request $request): JsonResponse
    {
        $metadata = $this->releases->loadMetadata();
        if ($metadata === null) {
            return response()->json(['error' => 'Plugin metadata not found'], 404);
        }

        $version = (string) ($metadata['version'] ?? '');
        if (! $this->releases->isValidVersion($version)) {
            return response()->json(['error' => 'Invalid version in metadata'], 500);
        }

        if (! $this->releases->zipExists($version)) {
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
     * GET /api/seo/plugin/download/{version}
     */
    public function download(Request $request, string $version): BinaryFileResponse|JsonResponse
    {
        if (! $request->hasValidSignature()) {
            return response()->json(['error' => 'Invalid or expired download link'], 403);
        }

        return $this->streamZip($version);
    }

    /**
     * GET /seo/wp-plugin/download/{version}
     */
    public function downloadForPanel(string $version): BinaryFileResponse|JsonResponse
    {
        return $this->streamZip($version);
    }

    private function streamZip(string $version): BinaryFileResponse|JsonResponse
    {
        if (! $this->releases->isValidVersion($version)) {
            return response()->json(['error' => 'Invalid version'], 400);
        }

        $absolutePath = $this->releases->absoluteZipPath($version);
        if ($absolutePath === null) {
            return response()->json(['error' => 'Requested version file not found on server.'], 404);
        }

        return response()->download($absolutePath, $this->releases->zipFileName($version), [
            'Content-Type' => 'application/zip',
        ]);
    }
}
