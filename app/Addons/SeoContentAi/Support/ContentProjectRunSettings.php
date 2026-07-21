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
    ) {}

    /**
     * @param  array<string, mixed>|null  $raw
     */
    public static function fromArray(?array $raw): self
    {
        if ($raw === null || $raw === []) {
            return self::defaults();
        }

        return new self(
            generatePostImages: filter_var($raw['generate_post_images'] ?? false, FILTER_VALIDATE_BOOL),
            settingsVersion: max(1, (int) ($raw['settings_version'] ?? self::VERSION)),
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
        return new self(generatePostImages: false, settingsVersion: self::VERSION);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public static function fromUserInput(array $input): self
    {
        return new self(
            generatePostImages: filter_var($input['generate_post_images'] ?? false, FILTER_VALIDATE_BOOL),
            settingsVersion: self::VERSION,
        );
    }

    /**
     * @return array{generate_post_images: bool, settings_version: int}
     */
    public function toArray(): array
    {
        return [
            'generate_post_images' => $this->generatePostImages,
            'settings_version' => $this->settingsVersion,
        ];
    }
}
