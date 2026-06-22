<?php

declare(strict_types=1);

namespace App\Services\ExternalPlugin;

use App\Exceptions\ExternalPlugin\InvalidWordPressPluginZipException;
use App\Exceptions\ExternalPlugin\WordPressPluginVersionExistsException;
use App\Exceptions\ExternalPlugin\WordPressPluginVersionNotFoundException;
use App\Models\WpOption;
use Illuminate\Support\Facades\Storage;

final class WordPressPluginReleaseService
{
    public function __construct(
        private readonly ExternalPluginManifest $manifest,
        private readonly ?WordPressPluginZipInspector $zipInspector = null,
    ) {}

    public static function forManifest(ExternalPluginManifest $manifest): self
    {
        return new self(
            $manifest,
            WordPressPluginZipInspector::forManifest($manifest),
        );
    }

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

        $latest = null;
        $older = $releases;

        $publishedVersion = trim((string) ($metadata['version'] ?? ''));
        if ($publishedVersion !== '' && $this->isValidVersion($publishedVersion)) {
            foreach ($releases as $release) {
                if ($release['version'] !== $publishedVersion) {
                    continue;
                }

                $latest = $release;
                $older = array_values(array_filter(
                    $releases,
                    static fn (array $item): bool => $item['version'] !== $publishedVersion,
                ));
                break;
            }
        }

        if ($latest === null && $releases !== []) {
            $latest = $releases[0];
            $older = array_slice($releases, 1);
        }

        return [
            'metadata' => $metadata,
            'latest' => $latest,
            'older' => $older,
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

        $pattern = '/^'.preg_quote($this->manifest->packagePrefix, '/').'-(\d+\.\d+\.\d+(?:[-+][\w.-]+)?)\.zip$/';
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
        $stored = WpOption::get($this->manifest->metadataOptionKey);
        if (is_array($stored) && $stored !== []) {
            return $stored;
        }

        $fromFile = $this->loadMetadataFromFile();
        if ($fromFile === null) {
            return null;
        }

        $this->saveMetadata($fromFile);
        $this->removeLegacyMetadataFile();

        return $fromFile;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function saveMetadata(array $metadata): void
    {
        WpOption::set($this->manifest->metadataOptionKey, $metadata);
    }

    /**
     * @return array<string, mixed>
     */
    public function defaultMetadata(): array
    {
        return [
            'name' => $this->manifest->label,
            'slug' => $this->manifest->slug,
            'version' => '0.0.0',
            'author' => 'TVH',
            'author_profile' => '',
            'requires' => '6.0',
            'tested' => '6.9',
            'requires_php' => '8.1',
            'last_updated' => '',
            'sections' => [
                'description' => 'Kết nối WordPress với Laravel Omnichannel Backend.',
                'installation' => 'Upload zip qua WordPress → Plugins → Add New → Upload, hoặc cài qua auto-update từ server Laravel.',
                'changelog' => '',
            ],
        ];
    }

    /**
     * @return array{version: string, filename: string, metadata: array<string, mixed>}
     *
     * @throws InvalidWordPressPluginZipException
     * @throws WordPressPluginVersionExistsException
     * @throws WordPressPluginVersionNotFoundException
     */
    public function publishRelease(
        string $sourceZipPath,
        ?string $manualVersion,
        string $changelog,
        bool $overwrite = false,
    ): array {
        $inspector = $this->zipInspector ?? WordPressPluginZipInspector::forManifest($this->manifest);

        $manualVersion = trim((string) $manualVersion);
        $version = $manualVersion !== ''
            ? $manualVersion
            : $inspector->extractVersion($sourceZipPath);

        if (! $this->isValidVersion($version)) {
            throw new WordPressPluginVersionNotFoundException(
                'Version must match semantic format, e.g. 1.0.30.',
            );
        }

        if ($this->zipExists($version) && ! $overwrite) {
            throw new WordPressPluginVersionExistsException(
                "Version {$version} already exists in storage. Enable overwrite to replace it.",
            );
        }

        $disk = Storage::disk('public');
        $relativePath = $this->zipRelativePath($version);
        $disk->makeDirectory($this->pluginDirectory());

        $stored = $disk->put($relativePath, (string) file_get_contents($sourceZipPath));
        if ($stored === false) {
            throw new InvalidWordPressPluginZipException('Failed to store plugin ZIP on the public disk.');
        }

        $metadata = $this->loadMetadata() ?? $this->defaultMetadata();
        $metadata['version'] = $version;
        $metadata['slug'] = $this->manifest->slug;
        $metadata['last_updated'] = now()->format('Y-m-d H:i:s');

        $sections = is_array($metadata['sections'] ?? null) ? $metadata['sections'] : [];
        $trimmedChangelog = trim($changelog);
        if ($trimmedChangelog !== '') {
            $existing = trim((string) ($sections['changelog'] ?? ''));
            $entry = $version.': '.$trimmedChangelog;
            $sections['changelog'] = $existing !== '' ? $entry."\n".$existing : $entry;
        }
        $metadata['sections'] = $sections;

        $this->saveMetadata($metadata);
        $this->removeLegacyMetadataFile();

        return [
            'version' => $version,
            'filename' => $this->zipFileName($version),
            'metadata' => $metadata,
        ];
    }

    public function zipRelativePath(string $version): string
    {
        return $this->pluginDirectory().'/'.$this->zipFileName($version);
    }

    public function zipFileName(string $version): string
    {
        return $this->manifest->packagePrefix.'-'.$version.'.zip';
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

    /**
     * @throws InvalidWordPressPluginZipException
     */
    public function deleteRelease(string $version): void
    {
        if (! $this->isValidVersion($version)) {
            throw new InvalidWordPressPluginZipException('Invalid version format.');
        }

        $metadata = $this->loadMetadata() ?? $this->defaultMetadata();
        $publishedVersion = trim((string) ($metadata['version'] ?? ''));
        if ($publishedVersion === $version) {
            throw new InvalidWordPressPluginZipException(
                'Cannot delete the currently published version. Publish another version first.',
            );
        }

        if (! $this->zipExists($version)) {
            throw new InvalidWordPressPluginZipException('Release file not found.');
        }

        Storage::disk('public')->delete($this->zipRelativePath($version));
    }

    public function removeLegacyMetadataFile(): void
    {
        $path = $this->metadataPath();
        if (Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }

    public function manifest(): ExternalPluginManifest
    {
        return $this->manifest;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadMetadataFromFile(): ?array
    {
        $path = $this->metadataPath();

        if (! Storage::disk('public')->exists($path)) {
            return null;
        }

        $raw = Storage::disk('public')->get($path);
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function pluginDirectory(): string
    {
        return 'plugins/'.$this->manifest->packagePrefix;
    }

    private function metadataPath(): string
    {
        return $this->pluginDirectory().'/info.json';
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        if ($bytes < 1048576) {
            return round($bytes / 1024, 1).' KB';
        }

        return round($bytes / 1048576, 2).' MB';
    }
}
