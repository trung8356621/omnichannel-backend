<?php

declare(strict_types=1);

namespace App\Core\Database;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Marks pending migrations as ran ONLY when schema proof says changes already exist.
 */
final class LegacySchemaMigrationReconciler
{
    /**
     * @return list<array{migration: string, path: string, status: string, reason: string}>
     */
    public function analyzePaths(array $absoluteDirs, string $connection): array
    {
        $ran = [];
        if (Schema::connection($connection)->hasTable('migrations')) {
            $ran = DB::connection($connection)->table('migrations')->pluck('migration')->all();
            $ran = array_fill_keys(array_map('strval', $ran), true);
        }

        $out = [];
        foreach ($absoluteDirs as $dir) {
            if (! is_dir($dir)) {
                continue;
            }
            foreach (glob($dir.DIRECTORY_SEPARATOR.'*.php') ?: [] as $file) {
                $base = basename($file, '.php');
                if (isset($ran[$base])) {
                    continue;
                }
                $out[] = $this->analyzeFile($file, $connection);
            }
        }

        usort($out, static fn (array $a, array $b): int => strcmp($a['migration'], $b['migration']));

        return $out;
    }

    /**
     * @param  list<array{migration: string, path: string, status: string, reason: string}>  $rows
     * @return list<array{migration: string, path: string, status: string, reason: string}>
     */
    public function apply(array $rows, string $connection): array
    {
        $applied = [];
        $batch = 1;
        if (Schema::connection($connection)->hasTable('migrations')) {
            $batch = ((int) DB::connection($connection)->table('migrations')->max('batch')) + 1;
        }

        foreach ($rows as $row) {
            if (($row['status'] ?? '') !== 'already_applied') {
                continue;
            }
            DB::connection($connection)->table('migrations')->insert([
                'migration' => $row['migration'],
                'batch' => $batch,
            ]);
            $row['status'] = 'recorded';
            $applied[] = $row;
        }

        return $applied;
    }

    /**
     * @return array{migration: string, path: string, status: string, reason: string}
     */
    public function analyzeFile(string $absolutePath, string $connection): array
    {
        $migration = basename($absolutePath, '.php');
        $source = (string) file_get_contents($absolutePath);
        $creates = $this->extractCreateTables($source);
        $columns = $this->extractAddedColumns($source);

        if ($creates === [] && $columns === []) {
            return [
                'migration' => $migration,
                'path' => $absolutePath,
                'status' => 'unknown',
                'reason' => 'Could not prove create-table/add-column intent from source',
            ];
        }

        foreach ($creates as $table) {
            if (! Schema::connection($connection)->hasTable($table)) {
                return [
                    'migration' => $migration,
                    'path' => $absolutePath,
                    'status' => 'pending',
                    'reason' => "Table [{$table}] missing — needs migrate",
                ];
            }
        }

        foreach ($columns as [$table, $column]) {
            if (! Schema::connection($connection)->hasTable($table)) {
                return [
                    'migration' => $migration,
                    'path' => $absolutePath,
                    'status' => 'pending',
                    'reason' => "Table [{$table}] missing for column [{$column}]",
                ];
            }
            if (! Schema::connection($connection)->hasColumn($table, $column)) {
                return [
                    'migration' => $migration,
                    'path' => $absolutePath,
                    'status' => 'pending',
                    'reason' => "Column [{$table}.{$column}] missing — needs migrate",
                ];
            }
        }

        $bits = [];
        if ($creates !== []) {
            $bits[] = 'tables exist: '.implode(', ', $creates);
        }
        if ($columns !== []) {
            $bits[] = 'columns exist: '.implode(', ', array_map(
                static fn (array $p): string => $p[0].'.'.$p[1],
                $columns,
            ));
        }

        return [
            'migration' => $migration,
            'path' => $absolutePath,
            'status' => 'already_applied',
            'reason' => implode('; ', $bits),
        ];
    }

    /**
     * @return list<string>
     */
    private function extractCreateTables(string $source): array
    {
        $tables = [];
        $patterns = [
            '/Schema(?:::\w+)?(?:\([^)]*\))?->create\(\s*[\'"]([^\'"]+)[\'"]/',
            '/\$schema->create\(\s*[\'"]([^\'"]+)[\'"]/',
            '/\$[a-zA-Z_][\w]*->create\(\s*[\'"]([^\'"]+)[\'"]/',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $source, $m)) {
                foreach ($m[1] as $table) {
                    $tables[] = $table;
                }
            }
        }

        return array_values(array_unique($tables));
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function extractAddedColumns(string $source): array
    {
        $pairs = [];

        $blockPatterns = [
            '/Schema(?:::\w+)?(?:\([^)]*\))?->table\(\s*[\'"]([^\'"]+)[\'"]\s*,\s*function\s*\([^)]*\)\s*(?::\s*void)?\s*\{(.*?)\}\s*\)\s*;/s',
            '/\$schema->table\(\s*[\'"]([^\'"]+)[\'"]\s*,\s*function\s*\([^)]*\)\s*(?::\s*void)?\s*\{(.*?)\}\s*\)\s*;/s',
            '/\$[a-zA-Z_][\w]*->table\(\s*[\'"]([^\'"]+)[\'"]\s*,\s*function\s*\([^)]*\)\s*(?::\s*void)?\s*\{(.*?)\}\s*\)\s*;/s',
        ];

        foreach ($blockPatterns as $blockPattern) {
            if (! preg_match_all($blockPattern, $source, $blocks, PREG_SET_ORDER)) {
                continue;
            }
            foreach ($blocks as $block) {
                $table = $block[1];
                $body = $block[2];
                if (preg_match_all('/\$table->(?:string|text|longText|mediumText|integer|bigInteger|unsignedBigInteger|boolean|json|dateTime|timestamp|timestampTz|float|decimal|uuid|char|enum|unsignedInteger)\(\s*[\'"]([^\'"]+)[\'"]/', $body, $cols)) {
                    foreach ($cols[1] as $col) {
                        $pairs[] = [$table, $col];
                    }
                }
            }
        }

        return $pairs;
    }
}
