<?php

declare(strict_types=1);

namespace App\Services\Automation;

use App\Support\Automation\AutomationConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Copy / verify / cleanup automation_* (+ business_events) từ SEO DB → core DB.
 */
final class AutomationCoreMigrationService
{
    /** @var list<string> */
    public const TABLES = [
        'business_events',
        'automation_rules',
        'automation_rule_versions',
        'automation_rule_nodes',
        'automation_rule_edges',
        'automation_rule_actions',
        'automation_rule_version_nodes',
        'automation_rule_version_edges',
        'automation_executions',
        'automation_node_executions',
        'automation_action_executions',
        'automation_action_runs',
        'automation_scheduler_heartbeats',
    ];

    /** Drop / rename order (children first). */
    /** @var list<string> */
    public const CLEANUP_ORDER = [
        'automation_node_executions',
        'automation_action_executions',
        'automation_executions',
        'automation_rule_version_edges',
        'automation_rule_version_nodes',
        'automation_rule_versions',
        'automation_rule_edges',
        'automation_rule_nodes',
        'automation_rule_actions',
        'automation_rules',
        'automation_action_runs',
        'automation_scheduler_heartbeats',
        'business_events',
    ];

    /** Bảng vận hành: conflict thì ghi đè từ source (không phải business data). */
    /** @var list<string> */
    private const OVERWRITE_ON_CONFLICT = [
        'automation_scheduler_heartbeats',
    ];

    /**
     * @return array<string, mixed>
     */
    public function dryRun(): array
    {
        return $this->buildInventoryReport(execute: false);
    }

    /**
     * @return array<string, mixed>
     */
    public function copy(bool $execute): array
    {
        $report = $this->newReport('copy');
        $source = AutomationConnection::source();
        $target = AutomationConnection::target();
        $chunk = max(50, (int) config('automation.chunk_size', 500));

        if ($source === $target) {
            $report['errors'][] = 'source_connection và target_connection trùng nhau.';
            $report['cutover_ready'] = false;
            $this->persistReport($report);

            return $report;
        }

        foreach (self::TABLES as $table) {
            $tableReport = [
                'source_count' => 0,
                'target_count_before' => 0,
                'copied' => 0,
                'already_present' => 0,
                'conflicts' => 0,
                'failed' => 0,
                'skipped' => 0,
                'status' => 'pending',
            ];

            try {
                if (! Schema::connection($source)->hasTable($table)) {
                    $tableReport['status'] = 'SKIPPED';
                    $tableReport['skipped'] = 1;
                    $report['tables'][$table] = $tableReport;
                    continue;
                }

                if (! Schema::connection($target)->hasTable($table)) {
                    $tableReport['status'] = 'FAILED';
                    $tableReport['failed'] = 1;
                    $report['errors'][] = "Thiếu bảng đích {$table} trên [{$target}]. Chạy migrate core trước.";
                    $report['tables'][$table] = $tableReport;
                    continue;
                }

                $schemaDiff = $this->compareColumnSets($source, $target, $table);
                if ($schemaDiff !== []) {
                    $report['schema_warnings'][$table] = $schemaDiff;
                }

                $sourceQuery = DB::connection($source)->table($table);
                $tableReport['source_count'] = (int) (clone $sourceQuery)->count();
                $tableReport['target_count_before'] = (int) DB::connection($target)->table($table)->count();

                if (! $execute) {
                    $tableReport['status'] = 'DRY_RUN';
                    $report['tables'][$table] = $tableReport;
                    continue;
                }

                $pk = $this->primaryKeyColumn($source, $table);
                $columns = $this->sharedColumns($source, $target, $table);

                if ($pk === null || $columns === []) {
                    $tableReport['status'] = 'FAILED';
                    $tableReport['failed'] = 1;
                    $report['errors'][] = "Không xác định được PK/columns cho {$table}.";
                    $report['tables'][$table] = $tableReport;
                    continue;
                }

                $lastId = 0;
                while (true) {
                    $rows = DB::connection($source)
                        ->table($table)
                        ->where($pk, '>', $lastId)
                        ->orderBy($pk)
                        ->limit($chunk)
                        ->get();

                    if ($rows->isEmpty()) {
                        break;
                    }

                    foreach ($rows as $row) {
                        $lastId = (int) $row->{$pk};
                        $payload = [];
                        foreach ($columns as $col) {
                            $payload[$col] = $row->{$col} ?? null;
                        }

                        $existing = DB::connection($target)->table($table)->where($pk, $lastId)->first();
                        if ($existing === null) {
                            DB::connection($target)->table($table)->insert($payload);
                            $tableReport['copied']++;
                            continue;
                        }

                        if ($this->rowsEqual($payload, (array) $existing, $columns)) {
                            $tableReport['already_present']++;
                            continue;
                        }

                        if (in_array($table, self::OVERWRITE_ON_CONFLICT, true)) {
                            DB::connection($target)->table($table)->where($pk, $lastId)->update($payload);
                            $tableReport['copied']++;
                            continue;
                        }

                        $tableReport['conflicts']++;
                        $report['conflicts'][] = [
                            'table' => $table,
                            'id' => $lastId,
                            'status' => 'CONFLICT',
                        ];
                    }
                }

                $tableReport['target_count'] = (int) DB::connection($target)->table($table)->count();
                $tableReport['status'] = $tableReport['conflicts'] > 0 || $tableReport['failed'] > 0
                    ? 'CONFLICT'
                    : 'COPIED';
            } catch (Throwable $e) {
                $tableReport['status'] = 'FAILED';
                $tableReport['failed']++;
                $report['errors'][] = "{$table}: ".$e->getMessage();
            }

            $report['tables'][$table] = $tableReport;
        }

        $report['finished_at'] = now()->toIso8601String();
        $report['cutover_ready'] = $report['errors'] === [] && $report['conflicts'] === [];
        $this->persistReport($report);

        return $report;
    }

    /**
     * @return array<string, mixed>
     */
    public function verify(): array
    {
        $report = $this->newReport('verify');
        $source = AutomationConnection::source();
        $target = AutomationConnection::target();
        $runtime = AutomationConnection::name();
        $chunk = max(50, (int) config('automation.chunk_size', 500));
        $allMatch = true;
        $sourceAbsentTargetPresent = 0;
        $targetHasData = false;

        foreach (self::TABLES as $table) {
            $entry = [
                'source_count' => 0,
                'target_count' => 0,
                'min_pk_source' => null,
                'max_pk_source' => null,
                'min_pk_target' => null,
                'max_pk_target' => null,
                'checksum_match' => false,
                'status' => 'missing',
            ];

            try {
                $sourceExists = Schema::connection($source)->hasTable($table);
                $targetExists = Schema::connection($target)->hasTable($table);

                if (! $sourceExists && ! $targetExists) {
                    $entry['status'] = 'SKIPPED';
                    $entry['checksum_match'] = true;
                    $report['tables'][$table] = $entry;
                    continue;
                }

                // Sau cutover + cleanup nguồn: source mất, target còn — không còn so checksum được.
                if (! $sourceExists && $targetExists) {
                    $entry['target_count'] = (int) DB::connection($target)->table($table)->count();
                    if ($entry['target_count'] > 0) {
                        $targetHasData = true;
                    }
                    $sourceAbsentTargetPresent++;

                    if ($runtime === $target) {
                        $entry['status'] = 'SOURCE_ABSENT_RUNTIME_TARGET';
                        $entry['checksum_match'] = true;
                        $report['tables'][$table] = $entry;
                        continue;
                    }

                    $entry['status'] = 'FAILED';
                    $allMatch = false;
                    $report['errors'][] = "Bảng {$table} thiếu ở source (runtime vẫn [{$runtime}], chưa trỏ target).";
                    $report['tables'][$table] = $entry;
                    continue;
                }

                if ($sourceExists && ! $targetExists) {
                    $entry['status'] = 'FAILED';
                    $allMatch = false;
                    $report['errors'][] = "Bảng {$table} thiếu ở target";
                    $report['tables'][$table] = $entry;
                    continue;
                }

                $pk = $this->primaryKeyColumn($source, $table) ?? 'id';
                $columns = $this->sharedColumns($source, $target, $table);

                $entry['source_count'] = (int) DB::connection($source)->table($table)->count();
                $entry['target_count'] = (int) DB::connection($target)->table($table)->count();
                if ($entry['target_count'] > 0) {
                    $targetHasData = true;
                }
                $entry['min_pk_source'] = DB::connection($source)->table($table)->min($pk);
                $entry['max_pk_source'] = DB::connection($source)->table($table)->max($pk);
                $entry['min_pk_target'] = DB::connection($target)->table($table)->min($pk);
                $entry['max_pk_target'] = DB::connection($target)->table($table)->max($pk);

                $sourceHash = $this->tableChecksum($source, $table, $pk, $columns, $chunk);
                $targetHash = $this->tableChecksum($target, $table, $pk, $columns, $chunk);
                $entry['checksum_source'] = $sourceHash;
                $entry['checksum_target'] = $targetHash;
                $entry['checksum_match'] = $sourceHash === $targetHash
                    && $entry['source_count'] === $entry['target_count'];

                if (! $entry['checksum_match']) {
                    $diff = $this->findFirstRowDiff($source, $target, $table, $pk, $columns, $chunk);
                    if ($diff !== null) {
                        $entry['first_diff'] = $diff;
                        $report['conflicts'][] = array_merge(['table' => $table, 'status' => 'CHECKSUM_DIFF'], $diff);
                    }
                    $allMatch = false;
                    $entry['status'] = 'MISMATCH';
                    $report['errors'][] = "Verify lệch: {$table}";
                } else {
                    $entry['status'] = 'verified';
                }
            } catch (Throwable $e) {
                $allMatch = false;
                $entry['status'] = 'FAILED';
                $report['errors'][] = "{$table}: ".$e->getMessage();
            }

            $report['tables'][$table] = $entry;
        }

        if ($sourceAbsentTargetPresent > 0 && $runtime === $target) {
            $report['notes'][] = 'Source tables absent; runtime đã trỏ target. Coi như post-cutover/cleanup — không so checksum nguồn.';
            if ($targetHasData) {
                $report['notes'][] = 'Target còn dữ liệu — smoke test runtime, không cần --verify so nguồn nữa.';
            }
        }

        $report['finished_at'] = now()->toIso8601String();
        $report['cutover_ready'] = $allMatch && $report['errors'] === [];
        // Post-cutover source gone: không đánh verified=true kiểu copy-parity (tránh mở cleanup nhầm).
        $report['verified'] = $report['cutover_ready'] && $sourceAbsentTargetPresent === 0;
        $report['post_cutover_source_absent'] = $sourceAbsentTargetPresent > 0 && $runtime === $target;
        $this->persistReport($report);

        return $report;
    }

    /**
     * @return array<string, mixed>
     */
    public function cleanupSource(bool $force, bool $renameOnly = true): array
    {
        $report = $this->newReport('cleanup');
        $source = AutomationConnection::source();

        if (! $force) {
            $report['errors'][] = 'Cleanup yêu cầu --force.';
            $report['cutover_ready'] = false;
            $this->persistReport($report);

            return $report;
        }

        $latest = $this->latestVerifyReport();
        if ($latest === null || ! ($latest['verified'] ?? false)) {
            $report['errors'][] = 'Chưa có verify report hợp lệ (verified=true). Cleanup bị chặn.';
            $report['cutover_ready'] = false;
            $this->persistReport($report);

            return $report;
        }

        $runtime = AutomationConnection::name();
        if ($runtime === $source) {
            $report['errors'][] = 'Runtime vẫn trỏ source. Đổi AUTOMATION_DB_CONNECTION sang core trước khi cleanup.';
            $report['cutover_ready'] = false;
            $this->persistReport($report);

            return $report;
        }

        $suffix = '_legacy_'.now()->format('Ymd');
        $driver = DB::connection($source)->getDriverName();

        try {
            if ($driver === 'mysql') {
                DB::connection($source)->statement('SET FOREIGN_KEY_CHECKS=0');
            }

            foreach (self::CLEANUP_ORDER as $table) {
                if (! Schema::connection($source)->hasTable($table)) {
                    $report['tables'][$table] = ['status' => 'SKIPPED'];
                    continue;
                }

                if ($renameOnly) {
                    $newName = $table.$suffix;
                    if (Schema::connection($source)->hasTable($newName)) {
                        $report['tables'][$table] = ['status' => 'FAILED', 'error' => "{$newName} đã tồn tại"];
                        $report['errors'][] = "Rename conflict: {$newName}";
                        continue;
                    }
                    Schema::connection($source)->rename($table, $newName);
                    $report['tables'][$table] = ['status' => 'RENAMED', 'to' => $newName];
                } else {
                    Schema::connection($source)->drop($table);
                    $report['tables'][$table] = ['status' => 'DROPPED'];
                }
            }
        } catch (Throwable $e) {
            $report['errors'][] = $e->getMessage();
        } finally {
            if ($driver === 'mysql') {
                DB::connection($source)->statement('SET FOREIGN_KEY_CHECKS=1');
            }
        }

        $report['finished_at'] = now()->toIso8601String();
        $report['cutover_ready'] = $report['errors'] === [];
        $this->persistReport($report);

        return $report;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildInventoryReport(bool $execute): array
    {
        $report = $this->copy($execute);

        return $report;
    }

    /**
     * @return array<string, mixed>
     */
    private function newReport(string $phase): array
    {
        return [
            'phase' => $phase,
            'source_connection' => AutomationConnection::source(),
            'target_connection' => AutomationConnection::target(),
            'runtime_connection' => AutomationConnection::name(),
            'started_at' => now()->toIso8601String(),
            'finished_at' => null,
            'tables' => [],
            'conflicts' => [],
            'errors' => [],
            'schema_warnings' => [],
            'notes' => [],
            'cutover_ready' => false,
            'verified' => false,
            'post_cutover_source_absent' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function persistReport(array $report): void
    {
        // Không dùng Storage/Flysystem — hosting thiếu ext-fileinfo (`finfo`) sẽ vỡ.
        $dir = $this->reportDirectoryAbsolute();
        if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new \RuntimeException('Cannot create automation migration report directory: '.$dir);
        }

        $payload = json_encode(
            $report,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ) ?: '{}';

        $filename = sprintf('automation-migration-%s.json', now()->format('Y-m-d-His'));
        file_put_contents($dir.DIRECTORY_SEPARATOR.$filename, $payload);
        file_put_contents(
            $dir.DIRECTORY_SEPARATOR.'latest-'.$report['phase'].'.json',
            $payload
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function latestVerifyReport(): ?array
    {
        $path = $this->reportDirectoryAbsolute().DIRECTORY_SEPARATOR.'latest-verify.json';
        if (! is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    private function reportDirectoryAbsolute(): string
    {
        $relative = trim((string) config('automation.report_directory', 'automation-migration'), '/\\');

        return storage_path('app/'.$relative);
    }

    private function primaryKeyColumn(string $connection, string $table): ?string
    {
        try {
            $sm = Schema::connection($connection)->getConnection()->getSchemaBuilder();
            $cols = $sm->getColumnListing($table);
            if (in_array('id', $cols, true)) {
                return 'id';
            }

            return $cols[0] ?? null;
        } catch (Throwable) {
            return 'id';
        }
    }

    /**
     * @return list<string>
     */
    private function sharedColumns(string $source, string $target, string $table): array
    {
        $sourceCols = Schema::connection($source)->getColumnListing($table);
        $targetCols = Schema::connection($target)->getColumnListing($table);

        return array_values(array_intersect($sourceCols, $targetCols));
    }

    /**
     * @return list<string>
     */
    private function compareColumnSets(string $source, string $target, string $table): array
    {
        $sourceCols = Schema::connection($source)->getColumnListing($table);
        $targetCols = Schema::connection($target)->getColumnListing($table);
        $onlySource = array_values(array_diff($sourceCols, $targetCols));
        $onlyTarget = array_values(array_diff($targetCols, $sourceCols));

        $warnings = [];
        if ($onlySource !== []) {
            $warnings[] = 'only_source: '.implode(',', $onlySource);
        }
        if ($onlyTarget !== []) {
            $warnings[] = 'only_target: '.implode(',', $onlyTarget);
        }

        return $warnings;
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     * @param  list<string>  $columns
     */
    private function rowsEqual(array $a, array $b, array $columns): bool
    {
        foreach ($columns as $col) {
            $left = $this->normalizeValue($a[$col] ?? null);
            $right = $this->normalizeValue($b[$col] ?? null);
            if ($left !== $right) {
                return false;
            }
        }

        return true;
    }

    private function normalizeValue(mixed $value): string
    {
        if ($value === null) {
            return "\0NULL";
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            if (is_nan($value) || is_infinite($value)) {
                return (string) $value;
            }

            // Tránh 1.0 vs 1 noise.
            if (floor($value) == $value) {
                return (string) (int) $value;
            }

            return rtrim(rtrim(sprintf('%.14F', $value), '0'), '.');
        }

        if (is_array($value)) {
            return $this->canonicalJson($value);
        }

        if (is_object($value)) {
            return $this->canonicalJson(json_decode(json_encode($value), true) ?? []);
        }

        $string = (string) $value;

        // tinyint(1) / boolean string từ PDO.
        if ($string === '0' || $string === '1') {
            return $string;
        }

        // JSON text MySQL có thể reorder key / đổi spacing sau INSERT.
        $trimmed = ltrim($string);
        if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
            $decoded = json_decode($string, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $this->canonicalJson($decoded);
            }
        }

        // Timestamp / datetime precision.
        if (preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(\.\d+)?$/', $string) === 1) {
            $normalized = str_replace('T', ' ', $string);
            $dot = strpos($normalized, '.');
            if ($dot !== false) {
                $normalized = substr($normalized, 0, $dot);
            }

            return $normalized;
        }

        return $string;
    }

    private function canonicalJson(mixed $value): string
    {
        $sorted = $this->sortJsonKeys($value);

        return json_encode($sorted, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }

    private function sortJsonKeys(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $isList = array_is_list($value);
        foreach ($value as $k => $v) {
            $value[$k] = $this->sortJsonKeys($v);
        }

        if (! $isList) {
            ksort($value);
        }

        return $value;
    }

    /**
     * @param  list<string>  $columns
     * @return array{id: int, columns: list<string>}|null
     */
    private function findFirstRowDiff(
        string $source,
        string $target,
        string $table,
        string $pk,
        array $columns,
        int $chunk,
    ): ?array {
        $lastId = 0;

        while (true) {
            $sourceRows = DB::connection($source)
                ->table($table)
                ->select($columns)
                ->where($pk, '>', $lastId)
                ->orderBy($pk)
                ->limit($chunk)
                ->get()
                ->keyBy($pk);

            if ($sourceRows->isEmpty()) {
                return null;
            }

            $ids = $sourceRows->keys()->all();
            $targetRows = DB::connection($target)
                ->table($table)
                ->select($columns)
                ->whereIn($pk, $ids)
                ->get()
                ->keyBy($pk);

            foreach ($sourceRows as $id => $sourceRow) {
                $lastId = (int) $id;
                $targetRow = $targetRows->get($id);
                if ($targetRow === null) {
                    return [
                        'id' => $lastId,
                        'columns' => [$pk.' missing on target'],
                        'hint' => 'Pause writers rồi chạy --execute để catch-up row mới.',
                    ];
                }

                $diffCols = [];
                foreach ($columns as $col) {
                    $left = $this->normalizeValue($sourceRow->{$col} ?? null);
                    $right = $this->normalizeValue($targetRow->{$col} ?? null);
                    if ($left !== $right) {
                        $diffCols[] = $col;
                    }
                }

                if ($diffCols !== []) {
                    return ['id' => $lastId, 'columns' => $diffCols];
                }
            }
        }
    }

    /**
     * @param  list<string>  $columns
     */
    private function tableChecksum(
        string $connection,
        string $table,
        string $pk,
        array $columns,
        int $chunk,
    ): string {
        $hash = hash_init('sha256');
        $lastId = 0;
        // Cố định thứ tự cột cho hash ổn định giữa 2 connection.
        $orderedColumns = $columns;
        sort($orderedColumns);

        while (true) {
            $rows = DB::connection($connection)
                ->table($table)
                ->select($columns)
                ->where($pk, '>', $lastId)
                ->orderBy($pk)
                ->limit($chunk)
                ->get();

            if ($rows->isEmpty()) {
                break;
            }

            foreach ($rows as $row) {
                $lastId = (int) ($row->{$pk} ?? 0);
                $parts = [];
                foreach ($orderedColumns as $col) {
                    $parts[] = $col.'='.$this->normalizeValue($row->{$col} ?? null);
                }
                hash_update($hash, implode('|', $parts)."\n");
            }
        }

        return hash_final($hash);
    }
}
