<?php

declare(strict_types=1);

namespace App\Services\ExternalPlugin;

use App\Exceptions\ExternalPlugin\InvalidWordPressPluginZipException;
use App\Exceptions\ExternalPlugin\WordPressPluginVersionNotFoundException;
use ZipArchive;

final class WordPressPluginZipInspector
{
    private const VERSION_PATTERN = '/Version:\s*([0-9\.]+)/i';

    public function __construct(
        private readonly string $pluginSlug,
    ) {}

    public static function forManifest(ExternalPluginManifest $manifest): self
    {
        return new self($manifest->packagePrefix);
    }

    /**
     * @throws InvalidWordPressPluginZipException
     * @throws WordPressPluginVersionNotFoundException
     */
    public function extractVersion(string $zipPath): string
    {
        if (! is_file($zipPath) || ! is_readable($zipPath)) {
            throw new InvalidWordPressPluginZipException('ZIP file is missing or not readable.');
        }

        $zip = new ZipArchive;
        $opened = $zip->open($zipPath);

        if ($opened !== true) {
            throw new InvalidWordPressPluginZipException('Cannot open ZIP archive. The file may be corrupted.');
        }

        try {
            $preferredMainFile = $this->pluginSlug.'/'.$this->pluginSlug.'.php';
            $version = $this->readVersionFromEntry($zip, $preferredMainFile);

            if ($version !== null) {
                return $version;
            }

            $fallbackMain = $this->pluginSlug.'.php';
            $version = $this->findVersionInZip($zip, static function (string $entryName) use ($fallbackMain): bool {
                return basename($entryName) === $fallbackMain;
            });

            if ($version !== null) {
                return $version;
            }

            $version = $this->findVersionInZip($zip, static function (string $entryName): bool {
                return str_ends_with(strtolower($entryName), '.php');
            });

            if ($version !== null) {
                return $version;
            }
        } finally {
            $zip->close();
        }

        throw new WordPressPluginVersionNotFoundException(
            'Could not find a main plugin PHP file with a valid "Version:" header inside the ZIP.',
        );
    }

    private function readVersionFromEntry(ZipArchive $zip, string $entryName): ?string
    {
        $content = $zip->getFromName($entryName);
        if (! is_string($content) || $content === '') {
            return null;
        }

        return $this->parseVersionHeader($content);
    }

    /**
     * @param  callable(string): bool  $entryMatcher
     */
    private function findVersionInZip(ZipArchive $zip, callable $entryMatcher): ?string
    {
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $entryName = $zip->getNameIndex($index);
            if (! is_string($entryName) || str_ends_with($entryName, '/')) {
                continue;
            }

            if (! $entryMatcher($entryName)) {
                continue;
            }

            $content = $zip->getFromIndex($index);
            if (! is_string($content) || ! str_contains($content, 'Plugin Name:')) {
                continue;
            }

            $version = $this->parseVersionHeader($content);
            if ($version !== null) {
                return $version;
            }
        }

        return null;
    }

    private function parseVersionHeader(string $content): ?string
    {
        if (! preg_match(self::VERSION_PATTERN, $content, $matches)) {
            return null;
        }

        $version = trim($matches[1]);

        return $version !== '' ? $version : null;
    }
}
