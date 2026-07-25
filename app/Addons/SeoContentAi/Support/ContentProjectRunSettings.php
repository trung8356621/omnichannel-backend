<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Support;

use App\Addons\SeoContentAi\Models\SeoProjectRun;

/**
 * Snapshot Run settings — immutable per Run after creation / user-confirmed rerun.
 */
final class ContentProjectRunSettings
{
    public const VERSION = 1;

    public function __construct(
        public readonly bool $generatePostImages = false,
        public readonly int $settingsVersion = self::VERSION,
        public readonly ?bool $usePhpEngine = null,
    ) {}

    /**
     * @param  array<string, mixed>|null  $raw
     */
    public static function fromArray(?array $raw): self
    {
        if ($raw === null || $raw === []) {
            return self::defaults();
        }

        $usePhp = null;
        if (array_key_exists('use_php_engine', $raw)) {
            $usePhp = filter_var($raw['use_php_engine'], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
            if ($usePhp === null && is_bool($raw['use_php_engine'])) {
                $usePhp = $raw['use_php_engine'];
            }
        }

        return new self(
            generatePostImages: filter_var($raw['generate_post_images'] ?? false, FILTER_VALIDATE_BOOL),
            settingsVersion: max(1, (int) ($raw['settings_version'] ?? self::VERSION)),
            usePhpEngine: $usePhp,
        );
    }

    public static function fromRun(?SeoProjectRun $run): self
    {
        if (! $run instanceof SeoProjectRun) {
            return self::defaults();
        }

        $settings = $run->settings;
        if (! is_array($settings)) {
            return self::defaults();
        }

        return self::fromArray($settings);
    }

    public static function defaults(): self
    {
        return new self(generatePostImages: false, settingsVersion: self::VERSION, usePhpEngine: null);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public static function fromUserInput(array $input): self
    {
        return self::fromArray($input);
    }

    /**
     * @return array{generate_post_images: bool, settings_version: int, use_php_engine?: bool, php_engine?: array<string, mixed>}
     */
    public function toArray(): array
    {
        $out = [
            'generate_post_images' => $this->generatePostImages,
            'settings_version' => $this->settingsVersion,
        ];
        if ($this->usePhpEngine !== null) {
            $out['use_php_engine'] = $this->usePhpEngine;
        }
        // Stamp sớm — bất biến sau create (Phase 1.8).
        if ($this->usePhpEngine === true) {
            $out['php_engine'] = [
                'enabled' => true,
                'use_php_engine' => true,
                'orchestration' => 'php',
            ];
        } elseif ($this->usePhpEngine === false) {
            $out['php_engine'] = [
                'enabled' => false,
                'use_php_engine' => false,
                'orchestration' => 'legacy',
            ];
        }

        return $out;
    }
}
