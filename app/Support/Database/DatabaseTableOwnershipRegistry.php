<?php

declare(strict_types=1);

namespace App\Support\Database;

use App\Contracts\DeclaresDatabaseTableOwnership;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;

/**
 * Gom ownership từ config + addon ServiceProvider.
 */
final class DatabaseTableOwnershipRegistry
{
    /** @var array<string, list<string>> table => owner connection names */
    private array $tableOwners = [];

    /** @var array<string, list<string>> connection => patterns */
    private array $patternsByConnection = [];

    /** @var list<string> */
    private array $ignoredTables = [];

    /** @var list<string> */
    private array $reviewRequiredPatterns = [];

    /** @var array<string, string> logical owner => resolved connection */
    private array $resolvedConnections = [];

    public function __construct(
        private readonly Application $app,
    ) {
        $this->boot();
    }

    /**
     * @return array<string, string> logical => connection
     */
    public function resolvedConnections(): array
    {
        return $this->resolvedConnections;
    }

    /**
     * @return list<string>
     */
    public function ignoredTables(): array
    {
        return $this->ignoredTables;
    }

    /**
     * @return list<string>
     */
    public function reviewRequiredPatterns(): array
    {
        return $this->reviewRequiredPatterns;
    }

    /**
     * @return array{status: string, owners: list<string>}
     */
    public function resolveOwner(string $table): array
    {
        if ($this->isIgnored($table)) {
            return ['status' => 'ignored', 'owners' => []];
        }

        $owners = $this->tableOwners[$table] ?? [];
        $owners = array_values(array_unique([...$owners, ...$this->ownersMatchingPatterns($table)]));

        if ($owners === []) {
            return ['status' => 'unknown', 'owners' => []];
        }

        if (count($owners) > 1) {
            return ['status' => 'conflict', 'owners' => $owners];
        }

        return ['status' => 'owned', 'owners' => $owners];
    }

    public function isIgnored(string $table): bool
    {
        return in_array($table, $this->ignoredTables, true);
    }

    public function requiresReview(string $table): bool
    {
        foreach ($this->reviewRequiredPatterns as $pattern) {
            if (fnmatch($pattern, $table)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public function allOwnedTables(): array
    {
        $tables = array_keys($this->tableOwners);
        sort($tables);

        return $tables;
    }

    private function boot(): void
    {
        $map = (array) Config::get('database_table_ownership.connection_map', []);
        $core = (string) Config::get('database.core_connection', 'mysql');

        foreach ($map as $logical => $connection) {
            $resolved = $connection === null || $connection === ''
                ? $core
                : (string) $connection;
            $this->resolvedConnections[(string) $logical] = $resolved;
        }

        $this->ignoredTables = array_values(array_unique(array_map(
            static fn (mixed $t): string => (string) $t,
            (array) Config::get('database_table_ownership.ignored_tables', ['migrations']),
        )));

        $this->reviewRequiredPatterns = array_values(array_map(
            static fn (mixed $p): string => (string) $p,
            (array) Config::get('database_table_ownership.review_required_patterns', []),
        ));

        foreach ((array) Config::get('database_table_ownership.owners', []) as $logical => $ownerConfig) {
            $this->ingestOwnerDeclaration((string) $logical, (array) $ownerConfig);
        }

        $this->ingestFromProviders();
    }

    /**
     * @param  array<string, mixed>  $ownerConfig
     */
    private function ingestOwnerDeclaration(string $logicalOrConnection, array $ownerConfig): void
    {
        $connection = $this->resolvedConnections[$logicalOrConnection]
            ?? $logicalOrConnection;

        if (! isset($this->resolvedConnections[$logicalOrConnection])
            && ! in_array($connection, $this->resolvedConnections, true)
        ) {
            $this->resolvedConnections[$logicalOrConnection] = $connection;
        }

        foreach ((array) ($ownerConfig['tables'] ?? []) as $table) {
            $this->claimTable((string) $table, $connection);
        }

        foreach ((array) ($ownerConfig['patterns'] ?? []) as $pattern) {
            $this->patternsByConnection[$connection] ??= [];
            $this->patternsByConnection[$connection][] = (string) $pattern;
        }
    }

    private function ingestFromProviders(): void
    {
        foreach (array_keys($this->app->getLoadedProviders()) as $providerClass) {
            $provider = $this->app->getProvider($providerClass);
            if (! $provider instanceof DeclaresDatabaseTableOwnership) {
                continue;
            }

            $declarations = $provider->databaseTableOwnership();
            if ($declarations === []) {
                continue;
            }

            $isList = array_is_list($declarations) && isset($declarations[0]) && is_array($declarations[0]);
            $items = $isList ? $declarations : [$declarations];

            foreach ($items as $item) {
                if (! is_array($item) || ! isset($item['connection'])) {
                    throw new InvalidArgumentException(
                        $providerClass.'::databaseTableOwnership() phải có key connection.',
                    );
                }

                $this->ingestOwnerDeclaration((string) $item['connection'], $item);
            }
        }
    }

    private function claimTable(string $table, string $connection): void
    {
        if ($table === '' || $this->isIgnored($table)) {
            return;
        }

        $this->tableOwners[$table] ??= [];
        if (! in_array($connection, $this->tableOwners[$table], true)) {
            $this->tableOwners[$table][] = $connection;
        }
    }

    /**
     * @return list<string>
     */
    private function ownersMatchingPatterns(string $table): array
    {
        $owners = [];
        foreach ($this->patternsByConnection as $connection => $patterns) {
            foreach ($patterns as $pattern) {
                if (fnmatch($pattern, $table)) {
                    $owners[] = $connection;
                    break;
                }
            }
        }

        return $owners;
    }
}
