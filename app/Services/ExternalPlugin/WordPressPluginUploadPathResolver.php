<?php

declare(strict_types=1);

namespace App\Services\ExternalPlugin;

use Illuminate\Support\Facades\Storage;

final class WordPressPluginUploadPathResolver
{
    private const UPLOAD_DIRECTORY = 'tmp/wp-plugin-uploads';

    public function resolve(mixed $uploaded, string $disk = 'local'): ?string
    {
        foreach ($this->normalizeCandidates($uploaded) as $candidate) {
            $absolute = $this->absolutePathForCandidate($candidate, $disk);
            if ($absolute !== null) {
                return $absolute;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function normalizeCandidates(mixed $uploaded): array
    {
        if ($uploaded === null || $uploaded === '') {
            return [];
        }

        if (is_object($uploaded)) {
            return $this->normalizeObjectCandidate($uploaded);
        }

        if (is_string($uploaded)) {
            $trimmed = trim($uploaded);

            return $trimmed !== '' ? [$trimmed] : [];
        }

        if (! is_array($uploaded)) {
            return [];
        }

        $paths = [];

        foreach ($uploaded as $key => $item) {
            if (is_string($key) && $this->looksLikeStoredPath($key)) {
                $paths[] = trim($key);
            }

            $paths = array_merge($paths, $this->normalizeCandidates($item));
        }

        return array_values(array_unique(array_filter($paths, static fn (string $path): bool => $path !== '')));
    }

    /**
     * @return list<string>
     */
    private function normalizeObjectCandidate(object $uploaded): array
    {
        if (method_exists($uploaded, 'getRealPath')) {
            $realPath = $uploaded->getRealPath();
            if (is_string($realPath) && $realPath !== '' && is_file($realPath)) {
                return [$realPath];
            }
        }

        if (method_exists($uploaded, 'path')) {
            $path = $uploaded->path();
            if (is_string($path) && $path !== '') {
                return [$path];
            }
        }

        if (method_exists($uploaded, 'getFilename')) {
            $filename = $uploaded->getFilename();
            if (is_string($filename) && $filename !== '') {
                return [$filename];
            }
        }

        return [];
    }

    private function absolutePathForCandidate(string $path, string $disk): ?string
    {
        $path = trim($path);
        if ($path === '') {
            return null;
        }

        if (is_file($path)) {
            return $path;
        }

        $normalized = $this->normalizeStoredReference($path);
        $storage = Storage::disk($disk);

        foreach ($this->relativePathCandidates($normalized) as $relative) {
            if ($storage->exists($relative)) {
                return $storage->path($relative);
            }
        }

        $diskRoot = rtrim(str_replace('\\', '/', $storage->path('')), '/');
        $fullPath = $diskRoot.'/'.$normalized;
        if (is_file($fullPath)) {
            return $fullPath;
        }

        foreach ($this->legacyAbsoluteCandidates($normalized) as $legacyPath) {
            if (is_file($legacyPath)) {
                return $legacyPath;
            }
        }

        return $this->findByBasename(basename($normalized), $disk);
    }

    private function normalizeStoredReference(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));

        if (str_contains($path, '://')) {
            $parsedPath = parse_url($path, PHP_URL_PATH);
            if (is_string($parsedPath) && $parsedPath !== '') {
                $path = $parsedPath;
            }
        }

        return ltrim($path, '/');
    }

    /**
     * @return list<string>
     */
    private function relativePathCandidates(string $normalized): array
    {
        $basename = basename($normalized);

        return array_values(array_unique(array_filter([
            $normalized,
            self::UPLOAD_DIRECTORY.'/'.$normalized,
            self::UPLOAD_DIRECTORY.'/'.$basename,
            'livewire-tmp/'.$normalized,
            'livewire-tmp/'.$basename,
        ])));
    }

    /**
     * Livewire temp / legacy paths ngoài root disk `local` (private).
     *
     * @return list<string>
     */
    private function legacyAbsoluteCandidates(string $normalized): array
    {
        $basename = basename($normalized);
        $appRoot = rtrim(str_replace('\\', '/', storage_path('app')), '/');

        return array_values(array_unique([
            $appRoot.'/livewire-tmp/'.$basename,
            $appRoot.'/livewire-tmp/'.$normalized,
            $appRoot.'/'.self::UPLOAD_DIRECTORY.'/'.$basename,
            $appRoot.'/private/'.self::UPLOAD_DIRECTORY.'/'.$basename,
        ]));
    }

    private function findByBasename(string $basename, string $disk): ?string
    {
        if ($basename === '' || ! str_ends_with(strtolower($basename), '.zip')) {
            return null;
        }

        $storage = Storage::disk($disk);
        $directories = [
            self::UPLOAD_DIRECTORY,
            'livewire-tmp',
        ];

        foreach ($directories as $directory) {
            if (! $storage->exists($directory)) {
                continue;
            }

            $direct = $directory.'/'.$basename;
            if ($storage->exists($direct)) {
                return $storage->path($direct);
            }

            foreach ($storage->files($directory) as $file) {
                if (basename($file) === $basename) {
                    return $storage->path($file);
                }
            }
        }

        foreach ($this->legacyAbsoluteCandidates($basename) as $legacyPath) {
            if (is_file($legacyPath)) {
                return $legacyPath;
            }
        }

        return null;
    }

    private function looksLikeStoredPath(string $value): bool
    {
        $value = trim(str_replace('\\', '/', $value));

        if ($value === '') {
            return false;
        }

        return str_contains($value, '/')
            || str_ends_with(strtolower($value), '.zip');
    }
}
