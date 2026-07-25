<?php

declare(strict_types=1);

namespace App\Support\Database;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Quét misplaced empty tables theo ownership registry.
 *
 * @phpstan-type Finding array{
 *     table: string,
 *     found_connection: string,
 *     expected_connection: string|null,
 *     rows: int|null,
 *     status: string,
 *     reason: string,
 *     review_required?: bool
 * }
 */
class MisplacedTableCleanupService
{
    public function __construct(
        private readonly DatabaseTableOwnershipRegistry $registry,
    ) {}

    /**
     * @return array{
     *     connections: array<string, mixed>,
     *     findings: list<Finding>,
     *     drop_candidates: list<Finding>,
     *     summary: array<string, int>
     * }
     */
    public function analyze(): array
    {
        $connections = $this->discoverScannableConnections();
        $fingerprints = [];
        foreach ($connections as $name => $meta) {
            $fingerprints[$name] = $meta['fingerprint'];
        }

        $tablesByConnection = [];
        foreach (array_keys($connections) as $connection) {
            try {
                $tablesByConnection[$connection] = $this->listTables($connection);
            } catch (Throwable $e) {
                $connections[$connection]['reachable'] = false;
                $connections[$connection]['error'] = $e->getMessage();
                $tablesByConnection[$connection] = [];
            }
        }

        /** @var list<Finding> $findings */
        $findings = [];

        foreach ($tablesByConnection as $foundConnection => $tables) {
            if (! ($connections[$foundConnection]['reachable'] ?? false)) {
                continue;
            }

            foreach ($tables as $table) {
                if ($this->registry->isIgnored($table)) {
                    continue;
                }

                $owner = $this->registry->resolveOwner($table);
                $review = $this->registry->requiresReview($table);

                if ($owner['status'] === 'unknown') {
                    $findings[] = $this->finding(
                        $table,
                        $foundConnection,
                        null,
                        null,
                        'UNKNOWN_OWNER',
                        'Table chưa khai báo ownership.',
                        $review,
                    );
                    continue;
                }

                if ($owner['status'] === 'conflict') {
                    $findings[] = $this->finding(
                        $table,
                        $foundConnection,
                        null,
                        null,
                        'CONFLICT',
                        'Ownership xung đột: '.implode(', ', $owner['owners']),
                        $review,
                    );
                    continue;
                }

                $expected = $owner['owners'][0];
                if ($expected === $foundConnection) {
                    if ($review) {
                        $rows = $this->safeCount($foundConnection, $table);
                        $findings[] = $this->finding(
                            $table,
                            $foundConnection,
                            $expected,
                            $rows,
                            'REVIEW_REQUIRED',
                            'Đúng owner; inventory cho task chuyển DB sau.',
                            true,
                        );
                    }
                    continue;
                }

                if ($this->samePhysical($fingerprints, $expected, $foundConnection)) {
                    $findings[] = $this->finding(
                        $table,
                        $foundConnection,
                        $expected,
                        null,
                        'SKIPPED',
                        'found_connection và expected_connection cùng database vật lý.',
                        $review,
                    );
                    continue;
                }

                if (! isset($connections[$expected]) || ! ($connections[$expected]['reachable'] ?? false)) {
                    $findings[] = $this->finding(
                        $table,
                        $foundConnection,
                        $expected,
                        null,
                        'WARNING',
                        'Owner connection không kết nối được; không xóa.',
                        $review,
                    );
                    continue;
                }

                $rows = $this->safeCount($foundConnection, $table);
                if ($rows === null) {
                    $findings[] = $this->finding(
                        $table,
                        $foundConnection,
                        $expected,
                        null,
                        'WARNING',
                        'Không lấy được row count đáng tin cậy.',
                        $review,
                    );
                    continue;
                }

                if ($rows > 0) {
                    $findings[] = $this->finding(
                        $table,
                        $foundConnection,
                        $expected,
                        $rows,
                        $review ? 'REVIEW_REQUIRED' : 'NON_EMPTY',
                        'Misplaced nhưng có dữ liệu; không xóa.',
                        $review,
                    );
                    continue;
                }

                if ($review && ! $this->isAutomationSafeToDrop($table, $expected)) {
                    $findings[] = $this->finding(
                        $table,
                        $foundConnection,
                        $expected,
                        0,
                        'REVIEW_REQUIRED',
                        'automation_* cần review; ownership chưa đủ chắc để drop.',
                        true,
                    );
                    continue;
                }

                $findings[] = $this->finding(
                    $table,
                    $foundConnection,
                    $expected,
                    0,
                    'DROP_CANDIDATE',
                    sprintf('owned by %s, found in %s, rows=0', $expected, $foundConnection),
                    $review,
                );
            }
        }

        $dropCandidates = array_values(array_filter(
            $findings,
            static fn (array $f): bool => $f['status'] === 'DROP_CANDIDATE',
        ));

        return [
            'connections' => $connections,
            'findings' => $findings,
            'drop_candidates' => $dropCandidates,
            'summary' => $this->summarize($findings, $tablesByConnection, $connections),
        ];
    }

    /**
     * @param  list<Finding>  $candidates
     * @return array{dropped: list<Finding>, errors: list<Finding>, skipped: list<Finding>}
     */
    public function executeDrops(array $candidates, bool $mutate): array
    {
        $dropped = [];
        $errors = [];
        $skipped = [];

        if (! $mutate) {
            foreach ($candidates as $candidate) {
                $skipped[] = [...$candidate, 'status' => 'DRY_RUN'];
            }

            return compact('dropped', 'errors', 'skipped');
        }

        $byConnection = [];
        foreach ($candidates as $candidate) {
            $byConnection[$candidate['found_connection']][] = $candidate;
        }

        foreach ($byConnection as $connection => $items) {
            $ordered = $this->orderTablesForDrop($connection, array_column($items, 'table'));
            $indexed = [];
            foreach ($items as $item) {
                $indexed[$item['table']] = $item;
            }

            $disabledFk = false;
            try {
                $disabledFk = $this->disableForeignKeyChecks($connection);
                foreach ($ordered as $table) {
                    $item = $indexed[$table];
                    try {
                        if (! Schema::connection($connection)->hasTable($table)) {
                            $skipped[] = [...$item, 'status' => 'SKIPPED', 'reason' => 'Table đã không còn tồn tại.'];
                            continue;
                        }

                        $rows = $this->safeCount($connection, $table);
                        if ($rows === null || $rows > 0) {
                            $skipped[] = [...$item, 'status' => 'NON_EMPTY', 'rows' => $rows, 'reason' => 'Re-check rows trước drop không còn 0.'];
                            continue;
                        }

                        $this->dropTable($connection, $table);
                        $dropped[] = [...$item, 'status' => 'dropped'];
                    } catch (Throwable $e) {
                        $errors[] = [...$item, 'status' => 'ERROR', 'reason' => $e->getMessage()];
                    }
                }
            } finally {
                if ($disabledFk) {
                    $this->enableForeignKeyChecks($connection);
                }
            }
        }

        return compact('dropped', 'errors', 'skipped');
    }

    protected function dropTable(string $connection, string $table): void
    {
        Schema::connection($connection)->dropIfExists($table);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function discoverScannableConnections(): array
    {
        $scanOwners = (array) Config::get('database_table_ownership.scan_owners', array_keys($this->registry->resolvedConnections()));
        $result = [];

        foreach ($scanOwners as $logical) {
            $connection = $this->registry->resolvedConnections()[(string) $logical]
                ?? (string) $logical;

            if (! is_array(Config::get('database.connections.'.$connection))) {
                continue;
            }

            $config = (array) Config::get('database.connections.'.$connection);
            $meta = [
                'logical' => (string) $logical,
                'connection' => $connection,
                'reachable' => false,
                'fingerprint' => DatabasePhysicalIdentity::fingerprint($config),
                'summary' => DatabasePhysicalIdentity::safeSummary($config),
            ];

            try {
                DB::connection($connection)->getPdo();
                $meta['reachable'] = true;
            } catch (Throwable $e) {
                $meta['error'] = $e->getMessage();
            }

            $result[$connection] = $meta;
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    private function listTables(string $connection): array
    {
        $conn = DB::connection($connection);
        $driver = $conn->getDriverName();

        if ($driver === 'sqlite') {
            $rows = $conn->select(
                "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name",
            );

            return array_values(array_map(
                static fn (object $row): string => (string) $row->name,
                $rows,
            ));
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $database = (string) $conn->getDatabaseName();
            $rows = $conn->select(
                'SELECT TABLE_NAME AS name FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = ? ORDER BY TABLE_NAME',
                [$database, 'BASE TABLE'],
            );

            return array_values(array_map(
                static fn (object $row): string => (string) $row->name,
                $rows,
            ));
        }

        $schema = Schema::connection($connection);
        if (method_exists($schema, 'getTableListing')) {
            $tables = $schema->getTableListing();

            return array_values(array_map(
                static function (mixed $table): string {
                    $name = is_string($table) ? $table : (string) $table;
                    if (str_contains($name, '.')) {
                        $parts = explode('.', $name);

                        return (string) end($parts);
                    }

                    return $name;
                },
                $tables,
            ));
        }

        return [];
    }

    private function safeCount(string $connection, string $table): ?int
    {
        try {
            if (! Schema::connection($connection)->hasTable($table)) {
                return null;
            }

            return (int) DB::connection($connection)->table($table)->count();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, string>  $fingerprints
     */
    private function samePhysical(array $fingerprints, string $a, string $b): bool
    {
        if ($a === $b) {
            return true;
        }

        return ($fingerprints[$a] ?? null) !== null
            && ($fingerprints[$a] ?? null) === ($fingerprints[$b] ?? null);
    }

    private function isAutomationSafeToDrop(string $table, string $expected): bool
    {
        // Ownership automation_* đã rõ từ model/migration = omi_seo_ai.
        // Empty copy ngoài owner được phép drop; bản đúng owner không vào nhánh này.
        return fnmatch('automation_*', $table) && $expected !== '';
    }

    /**
     * @param  list<string>  $tables
     * @return list<string>
     */
    private function orderTablesForDrop(string $connection, array $tables): array
    {
        // Ưu tiên dependency-aware nếu schema hỗ trợ; fallback giữ nguyên + FK checks off.
        try {
            $schema = Schema::connection($connection);
            if (! method_exists($schema, 'getForeignKeys')) {
                return array_values($tables);
            }

            $set = array_fill_keys($tables, true);
            $dependents = [];
            foreach ($tables as $table) {
                $dependents[$table] = [];
                foreach ($schema->getForeignKeys($table) as $fk) {
                    $ref = $fk['foreign_table'] ?? null;
                    if (is_string($ref) && isset($set[$ref]) && $ref !== $table) {
                        $dependents[$table][] = $ref;
                    }
                }
            }

            $ordered = [];
            $visiting = [];
            $visit = function (string $table) use (&$visit, &$ordered, &$visiting, $dependents): void {
                if (in_array($table, $ordered, true)) {
                    return;
                }
                if (isset($visiting[$table])) {
                    return;
                }
                $visiting[$table] = true;
                foreach ($dependents[$table] ?? [] as $ref) {
                    $visit($ref);
                }
                unset($visiting[$table]);
                $ordered[] = $table;
            };

            // Drop child trước parent: đảo topo của "depends on".
            $childrenFirst = [];
            foreach ($tables as $table) {
                foreach ($dependents[$table] ?? [] as $parent) {
                    $childrenFirst[$parent][] = $table;
                }
            }

            $ordered = [];
            $visiting = [];
            $visitChild = function (string $table) use (&$visitChild, &$ordered, &$visiting, $childrenFirst): void {
                if (in_array($table, $ordered, true)) {
                    return;
                }
                if (isset($visiting[$table])) {
                    return;
                }
                $visiting[$table] = true;
                foreach ($childrenFirst[$table] ?? [] as $child) {
                    $visitChild($child);
                }
                unset($visiting[$table]);
                $ordered[] = $table;
            };

            foreach ($tables as $table) {
                $visitChild($table);
            }

            return $ordered;
        } catch (Throwable) {
            return array_values($tables);
        }
    }

    private function disableForeignKeyChecks(string $connection): bool
    {
        $conn = DB::connection($connection);
        $driver = $conn->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $conn->statement('SET FOREIGN_KEY_CHECKS=0');

            return true;
        }

        if ($driver === 'sqlite') {
            $conn->statement('PRAGMA foreign_keys = OFF');

            return true;
        }

        return false;
    }

    private function enableForeignKeyChecks(string $connection): void
    {
        $conn = DB::connection($connection);
        $driver = $conn->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $conn->statement('SET FOREIGN_KEY_CHECKS=1');

            return;
        }

        if ($driver === 'sqlite') {
            $conn->statement('PRAGMA foreign_keys = ON');
        }
    }

    /**
     * @return Finding
     */
    private function finding(
        string $table,
        string $found,
        ?string $expected,
        ?int $rows,
        string $status,
        string $reason,
        bool $review = false,
    ): array {
        $item = [
            'table' => $table,
            'found_connection' => $found,
            'expected_connection' => $expected,
            'rows' => $rows,
            'status' => $status,
            'reason' => $reason,
        ];

        if ($review) {
            $item['review_required'] = true;
        }

        return $item;
    }

    /**
     * @param  list<Finding>  $findings
     * @param  array<string, list<string>>  $tablesByConnection
     * @param  array<string, array<string, mixed>>  $connections
     * @return array<string, int>
     */
    private function summarize(array $findings, array $tablesByConnection, array $connections): array
    {
        $counts = [
            'connections_scanned' => count(array_filter($connections, static fn (array $c): bool => (bool) ($c['reachable'] ?? false))),
            'tables_inspected' => array_sum(array_map('count', $tablesByConnection)),
            'drop_candidates' => 0,
            'non_empty' => 0,
            'unknown_owner' => 0,
            'conflicts' => 0,
            'warnings' => 0,
            'review_required' => 0,
            'skipped' => 0,
        ];

        foreach ($findings as $finding) {
            match ($finding['status']) {
                'DROP_CANDIDATE' => $counts['drop_candidates']++,
                'NON_EMPTY' => $counts['non_empty']++,
                'UNKNOWN_OWNER' => $counts['unknown_owner']++,
                'CONFLICT' => $counts['conflicts']++,
                'WARNING' => $counts['warnings']++,
                'REVIEW_REQUIRED' => $counts['review_required']++,
                'SKIPPED' => $counts['skipped']++,
                default => null,
            };
        }

        return $counts;
    }
}
