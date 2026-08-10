<?php

declare(strict_types=1);

namespace App\Core\Database;

/**
 * Blocks destructive dual-DB rebuild against real/legacy databases.
 */
final class DestructiveMigrationGuard
{
    public const BLOCK_PREFIX = 'DESTRUCTIVE MIGRATION BLOCKED';

    /**
     * @param  array<string, mixed>|null  $config  Optional override (tests); null reads config('refactor_migrate')
     */
    public function __construct(
        private readonly ?array $config = null,
    ) {}

    /**
     * @param  list<string>  $databaseNames
     * @return list<string> Normalized non-empty names that will be destroyed
     *
     * @throws \RuntimeException when destroy is not allowed
     */
    public function assertMayDestroy(array $databaseNames, bool $confirmDestroyTestDb, ?string $appEnv = null): array
    {
        $env = strtolower((string) ($appEnv ?? (function_exists('app') ? app()->environment() : 'production')));
        $names = $this->normalizeNames($databaseNames);

        if ($names === []) {
            throw new \RuntimeException(self::BLOCK_PREFIX.': no target database names resolved.');
        }

        $protected = [];
        foreach ($names as $name) {
            if ($this->isProtected($name)) {
                $protected[] = $name;
            }
        }

        if ($protected !== []) {
            throw new \RuntimeException(
                self::BLOCK_PREFIX."\n"
                ."Refusing to drop protected / production-like database(s):\n"
                .'  - '.implode("\n  - ", $protected)."\n"
                ."Use disposable names matching *_test (or APP_ENV=testing).\n"
                .'--confirm-destroy-test-db cannot override protected names.'
            );
        }

        if ($env === 'testing') {
            return $names;
        }

        $allDisposable = true;
        foreach ($names as $name) {
            if (! $this->isDisposable($name)) {
                $allDisposable = false;
                break;
            }
        }

        if ($allDisposable) {
            return $names;
        }

        if ($confirmDestroyTestDb) {
            throw new \RuntimeException(
                self::BLOCK_PREFIX."\n"
                ."--confirm-destroy-test-db was set but one or more targets are not disposable test DBs:\n"
                .'  - '.implode("\n  - ", $names)."\n"
                .'Rename targets to *_test (or set APP_ENV=testing).'
            );
        }

        throw new \RuntimeException(
            self::BLOCK_PREFIX."\n"
            ."refactor:migrate-fresh refuses non-test databases:\n"
            .'  - '.implode("\n  - ", $names)."\n"
            ."Allowed when: APP_ENV=testing, OR every DB matches disposable pattern (*_test), "
            ."OR protected names are absent and targets are disposable.\n"
            .'For legacy/real data use: php artisan refactor:migrate'
        );
    }

    public function isProtected(string $databaseName): bool
    {
        $name = strtolower(trim($databaseName));
        if ($name === '' || $name === ':memory:') {
            return false;
        }

        $exact = $this->cfg('protected_database_names', []);
        foreach ($exact as $item) {
            if ($name === strtolower((string) $item)) {
                return true;
            }
        }

        $patterns = $this->cfg('protected_database_patterns', []);
        foreach ($patterns as $pattern) {
            if ($this->matchesPattern($name, strtolower((string) $pattern))) {
                return true;
            }
        }

        return false;
    }

    public function isDisposable(string $databaseName): bool
    {
        $name = strtolower(trim($databaseName));
        if ($name === '' || $name === ':memory:') {
            return true;
        }

        if ($this->isProtected($name)) {
            return false;
        }

        $patterns = $this->cfg('disposable_database_patterns', []);
        foreach ($patterns as $pattern) {
            if ($this->matchesPattern($name, strtolower((string) $pattern))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $databaseNames
     * @return list<string>
     */
    private function normalizeNames(array $databaseNames): array
    {
        $out = [];
        foreach ($databaseNames as $name) {
            $trimmed = trim((string) $name);
            if ($trimmed === '') {
                continue;
            }
            $out[] = $trimmed;
        }

        return array_values(array_unique($out));
    }

    private function matchesPattern(string $name, string $pattern): bool
    {
        if ($pattern === '') {
            return false;
        }

        $regex = '/^'.str_replace(['\\*', '\\?'], ['.*', '.'], preg_quote($pattern, '/')).'$/i';

        return (bool) preg_match($regex, $name);
    }

    /**
     * @param  mixed  $default
     * @return mixed
     */
    private function cfg(string $key, mixed $default = null): mixed
    {
        if ($this->config !== null) {
            return $this->config[$key] ?? $default;
        }

        if (function_exists('config')) {
            return config('refactor_migrate.'.$key, $default);
        }

        return $default;
    }
}
