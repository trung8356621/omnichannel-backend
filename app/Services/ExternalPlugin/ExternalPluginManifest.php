<?php

declare(strict_types=1);

namespace App\Services\ExternalPlugin;

final readonly class ExternalPluginManifest
{
    public function __construct(
        public string $slug,
        public string $label,
        public string $platform,
        public string $packagePrefix,
        public string $metadataOptionKey,
        public string $sourceAddonSlug,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromAddonConfig(array $data, string $sourceAddonSlug): ?self
    {
        $slug = trim((string) ($data['slug'] ?? ''));
        if ($slug === '') {
            return null;
        }

        $packagePrefix = trim((string) ($data['package_prefix'] ?? $slug));
        $metadataKey = trim((string) ($data['metadata_option_key'] ?? 'wp_plugin_'.$slug.'_info'));
        $label = trim((string) ($data['label'] ?? $slug));
        $platform = trim((string) ($data['platform'] ?? 'wordpress'));

        return new self(
            slug: $slug,
            label: $label,
            platform: $platform !== '' ? $platform : 'wordpress',
            packagePrefix: $packagePrefix,
            metadataOptionKey: $metadataKey,
            sourceAddonSlug: $sourceAddonSlug,
        );
    }
}
