<?php

declare(strict_types=1);

namespace App\Core\Addon;

/**
 * Immutable addon manifest parsed from addon.json.
 */
final class AddonManifest
{
    /**
     * @param  list<string>  $provides
     * @param  list<string>  $requires
     * @param  list<string>  $optional
     * @param  list<string>  $extraProviders
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly string $slug,
        public readonly string $name,
        public readonly string $version,
        public readonly string $provider,
        public readonly string $path,
        public readonly array $provides = [],
        public readonly array $requires = [],
        public readonly array $optional = [],
        public readonly bool $legacy = false,
        public readonly bool $registerEarly = false,
        public readonly ?string $panelProvider = null,
        public readonly array $extraProviders = [],
        public readonly array $raw = [],
    ) {}

    /**
     * @param  array<string, mixed>  $meta
     */
    public static function fromArray(array $meta, string $path): self
    {
        $slug = trim((string) ($meta['slug'] ?? ''));
        if ($slug === '') {
            throw new \InvalidArgumentException("Addon manifest at [{$path}] missing slug.");
        }

        $provider = trim((string) ($meta['provider'] ?? ''));
        if ($provider === '') {
            throw new \InvalidArgumentException("Addon [{$slug}] missing provider.");
        }

        $panel = trim((string) ($meta['panel_provider'] ?? ''));

        return new self(
            slug: $slug,
            name: trim((string) ($meta['name'] ?? $slug)),
            version: trim((string) ($meta['version'] ?? '0.0.0')),
            provider: $provider,
            path: $path,
            provides: self::stringList($meta['provides'] ?? []),
            requires: self::stringList($meta['requires'] ?? []),
            optional: self::stringList($meta['optional'] ?? []),
            legacy: (bool) ($meta['legacy'] ?? false),
            registerEarly: (bool) ($meta['register_early'] ?? false),
            panelProvider: $panel !== '' ? $panel : null,
            extraProviders: self::stringList($meta['providers'] ?? []),
            raw: $meta,
        );
    }

    /**
     * @return list<string>
     */
    public function allProviderClasses(): array
    {
        $list = [$this->provider];
        if ($this->panelProvider !== null) {
            $list[] = $this->panelProvider;
        }
        foreach ($this->extraProviders as $extra) {
            $list[] = $extra;
        }

        return array_values(array_unique($list));
    }

    /**
     * @param  mixed  $value
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            $s = trim((string) $item);
            if ($s !== '') {
                $out[] = $s;
            }
        }

        return array_values(array_unique($out));
    }
}
