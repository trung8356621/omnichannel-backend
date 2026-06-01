<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use Illuminate\Support\Facades\Storage;

final class WordPressPluginReleaseService
{
    public const PLUGIN_SLUG = 'omi-seo-ai-bridge';

    /**
     * @return array{
     *     metadata: array<string, mixed>,
     *     latest: ?array{version: string, filename: string, size: int, size_label: string, modified_at: ?string},
     *     older: list<array{version: string, filename: string, size: int, size_label: string, modified_at: ?string}>,
     *     has_packages: bool,
     * }
     */
    public function overview(): array
    {
        $releases = $this->listReleases();
        $metadata = $this->loadMetadata() ?? $this->defaultMetadata();

        if ($releases !== []) {
            $metadata['version'] = $releases[0]['version'];
        }

        return [
            'metadata' => $metadata,
            'latest' => $releases[0] ?? null,
            'older' => array_slice($releases, 1),
            'has_packages' => $releases !== [],
        ];
    }

    /**
     * @return list<array{version: string, filename: string, size: int, size_label: string, modified_at: ?string}>
     */
    public function listReleases(): array
    {
        $disk = Storage::disk('public');
        $dir = $this->pluginDirectory();

        if (! $disk->exists($dir)) {
            return [];
        }

        $pattern = '/^' . preg_quote(self::PLUGIN_SLUG, '/') . '-(\d+\.\d+\.\d+(?:[-+][\w.-]+)?)\.zip$/';
        $releases = [];

        foreach ($disk->files($dir) as $path) {
            $basename = basename($path);
            if (! preg_match($pattern, $basename, $matches)) {
                continue;
            }

            $version = $matches[1];
            if (! $this->isValidVersion($version)) {
                continue;
            }

            $size = $disk->size($path);

            $releases[] = [
                'version' => $version,
                'filename' => $basename,
                'size' => $size,
                'size_label' => $this->formatBytes($size),
                'modified_at' => date('Y-m-d H:i', $disk->lastModified($path)),
            ];
        }

        usort($releases, static fn (array $a, array $b): int => version_compare($b['version'], $a['version']));

        return $releases;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function loadMetadata(): ?array
    {
        $path = $this->metadataPath();

        if (! Storage::disk('public')->exists($path)) {
            return null;
        }

        $raw = Storage::disk('public')->get($path);
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function defaultMetadata(): array
    {
        return [
            'name' => 'TVH SEO AI Bridge',
            'slug' => self::PLUGIN_SLUG,
            'version' => '0.0.0',
            'author' => 'TVH',
            'author_profile' => '',
            'requires' => '6.0',
            'tested' => '6.7',
            'requires_php' => '8.1',
            'last_updated' => '',
            'sections' => [
                'description' => 'Kết nối WordPress với Laravel Omnichannel Backend để đồng bộ nội dung TVH SEO AI.',
                'installation' => 'Upload zip qua WordPress → Plugins → Add New → Upload, hoặc cài qua auto-update từ server này.',
                'changelog' => '',
            ],
        ];
    }

    public function zipRelativePath(string $version): string
    {
        return $this->pluginDirectory() . '/' . $this->zipFileName($version);
    }

    public function zipFileName(string $version): string
    {
        return self::PLUGIN_SLUG . '-' . $version . '.zip';
    }

    public function absoluteZipPath(string $version): ?string
    {
        $relativePath = $this->zipRelativePath($version);

        if (! Storage::disk('public')->exists($relativePath)) {
            return null;
        }

        return Storage::disk('public')->path($relativePath);
    }

    public function zipExists(string $version): bool
    {
        return Storage::disk('public')->exists($this->zipRelativePath($version));
    }

    public function isValidVersion(string $version): bool
    {
        return $version !== '' && (bool) preg_match('/^\d+\.\d+\.\d+(?:[-+][\w.-]+)?$/', $version);
    }

    private function pluginDirectory(): string
    {
        return 'plugins/' . self::PLUGIN_SLUG;
    }

    private function metadataPath(): string
    {
        return $this->pluginDirectory() . '/info.json';
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }

        if ($bytes < 1048576) {
            return round($bytes / 1024, 1) . ' KB';
        }

        return round($bytes / 1048576, 2) . ' MB';
    }
}
