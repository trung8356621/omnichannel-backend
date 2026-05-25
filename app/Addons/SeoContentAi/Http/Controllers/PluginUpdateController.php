<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PluginUpdateController extends Controller
{
    private const PLUGIN_SLUG = 'omi-seo-ai-bridge';

    /**
     * GET /api/seo/plugin/update-check
     */
    public function checkUpdate(Request $request): JsonResponse
    {
        $metadata = $this->loadMetadata();
        if ($metadata === null) {
            return response()->json(['error' => 'Plugin metadata not found'], 404);
        }

        $version = (string) ($metadata['version'] ?? '');
        if (! $this->isValidVersion($version)) {
            return response()->json(['error' => 'Invalid version in metadata'], 500);
        }

        if (! $this->zipExists($version)) {
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

        if (! $this->isValidVersion($version)) {
            return response()->json(['error' => 'Invalid version'], 400);
        }

        $zipName = $this->zipFileName($version);
        $relativePath = $this->zipRelativePath($version);

        if (! Storage::disk('public')->exists($relativePath)) {
            return response()->json(['error' => 'Requested version file not found on server.'], 404);
        }

        $absolutePath = Storage::disk('public')->path($relativePath);

        return response()->download($absolutePath, $zipName, [
            'Content-Type' => 'application/zip',
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadMetadata(): ?array
    {
        $jsonPath = $this->metadataPath();

        if (! Storage::disk('public')->exists($jsonPath)) {
            return null;
        }

        $raw = Storage::disk('public')->get($jsonPath);
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function metadataPath(): string
    {
        return 'plugins/' . self::PLUGIN_SLUG . '/info.json';
    }

    private function zipRelativePath(string $version): string
    {
        return 'plugins/' . self::PLUGIN_SLUG . '/' . $this->zipFileName($version);
    }

    private function zipFileName(string $version): string
    {
        return self::PLUGIN_SLUG . '-' . $version . '.zip';
    }

    private function zipExists(string $version): bool
    {
        return Storage::disk('public')->exists($this->zipRelativePath($version));
    }

    private function isValidVersion(string $version): bool
    {
        return $version !== '' && (bool) preg_match('/^\d+\.\d+\.\d+(?:[-+][\w.-]+)?$/', $version);
    }
}
