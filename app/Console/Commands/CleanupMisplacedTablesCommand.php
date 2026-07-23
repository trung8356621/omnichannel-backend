<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Database\MisplacedTableCleanupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Throwable;

final class CleanupMisplacedTablesCommand extends Command
{
    protected $signature = 'database:cleanup-misplaced-tables
        {--dry-run : Chỉ xem trước (mặc định khi không có --execute)}
        {--execute : Thực sự DROP table misplaced rỗng}
        {--force : Bỏ bước xác nhận khi dùng cùng --execute}';

    protected $description = 'Dọn table migration tạo nhầm database (chỉ xóa khi ownership rõ và rows=0)';

    public function handle(MisplacedTableCleanupService $service): int
    {
        $execute = (bool) $this->option('execute');
        $force = (bool) $this->option('force');
        $dryRun = ! $execute || (bool) $this->option('dry-run');

        if ($force && ! $execute) {
            $this->error('--force không kích hoạt xóa. Cần --execute --force.');

            return self::FAILURE;
        }

        if ($execute && (bool) $this->option('dry-run')) {
            $this->warn('Có cả --execute và --dry-run → ưu tiên dry-run, không mutate schema.');
            $execute = false;
            $dryRun = true;
        }

        $startedAt = now()->toIso8601String();
        $mode = $execute ? 'execute' : 'dry-run';

        try {
            $analysis = $service->analyze();
        } catch (Throwable $e) {
            $this->error('Analyze failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->printConnectionSummary($analysis['connections']);
        $this->printSummary($analysis['summary']);
        $this->printFindings($analysis['findings'], $dryRun);

        $candidates = $analysis['drop_candidates'];
        $dropped = [];
        $errors = [];
        $skipped = [];

        if ($candidates === []) {
            $this->info('Không có empty misplaced table đủ điều kiện drop.');
        } elseif ($dryRun) {
            $result = $service->executeDrops($candidates, false);
            $skipped = $result['skipped'];
        } else {
            $this->newLine();
            $this->warn(sprintf('This will drop %d empty misplaced tables. Continue?', count($candidates)));
            if (! $force && ! $this->confirm('Continue?', false)) {
                $this->warn('Đã hủy. Không có schema mutation.');

                return self::SUCCESS;
            }

            $result = $service->executeDrops($candidates, true);
            $dropped = $result['dropped'];
            $errors = $result['errors'];
            $skipped = $result['skipped'];

            $this->info(sprintf('Dropped: %d | Errors: %d | Skipped: %d', count($dropped), count($errors), count($skipped)));
        }

        $reportPath = $this->writeReport([
            'mode' => $mode,
            'started_at' => $startedAt,
            'finished_at' => now()->toIso8601String(),
            'connections' => $analysis['connections'],
            'summary' => $analysis['summary'],
            'drop_candidates' => $candidates,
            'findings' => $analysis['findings'],
            'dropped' => $dropped,
            'skipped' => $skipped,
            'errors' => $errors,
        ]);

        $this->info('Report: '.$reportPath);

        return $errors === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<string, array<string, mixed>>  $connections
     */
    private function printConnectionSummary(array $connections): void
    {
        $this->info('Connections scanned:');
        foreach ($connections as $name => $meta) {
            $summary = $meta['summary'] ?? [];
            $reach = ($meta['reachable'] ?? false) ? 'ok' : 'UNREACHABLE';
            $this->line(sprintf(
                '  - %s [%s] driver=%s host=%s port=%s database=%s',
                $name,
                $reach,
                $summary['driver'] ?? '?',
                $summary['host'] ?? '',
                $summary['port'] ?? '',
                $summary['database'] ?? '',
            ));
        }
    }

    /**
     * @param  array<string, int>  $summary
     */
    private function printSummary(array $summary): void
    {
        $this->newLine();
        $this->info('Summary:');
        $this->line('  Connections scanned: '.($summary['connections_scanned'] ?? 0));
        $this->line('  Tables inspected: '.($summary['tables_inspected'] ?? 0));
        $this->line('  Misplaced empty tables: '.($summary['drop_candidates'] ?? 0));
        $this->line('  Non-empty misplaced tables: '.($summary['non_empty'] ?? 0));
        $this->line('  Unknown ownership: '.($summary['unknown_owner'] ?? 0));
        $this->line('  Ownership conflicts: '.($summary['conflicts'] ?? 0));
        $this->line('  Warnings: '.($summary['warnings'] ?? 0));
        $this->line('  Review required: '.($summary['review_required'] ?? 0));
    }

    /**
     * @param  list<array<string, mixed>>  $findings
     */
    private function printFindings(array $findings, bool $dryRun): void
    {
        if ($findings === []) {
            return;
        }

        $this->newLine();
        $this->info('Findings:');
        foreach ($findings as $finding) {
            $prefix = match ($finding['status']) {
                'DROP_CANDIDATE' => $dryRun ? '[DRY-RUN] DROP' : '[CANDIDATE] DROP',
                default => '['.$finding['status'].']',
            };

            $this->line(sprintf(
                '  %s %s.%s — %s (rows=%s)',
                $prefix,
                $finding['found_connection'],
                $finding['table'],
                $finding['reason'],
                $finding['rows'] === null ? 'n/a' : (string) $finding['rows'],
            ));
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writeReport(array $payload): string
    {
        $relativeDir = (string) Config::get('database_table_ownership.report_directory', 'app/database-cleanup');
        $filename = 'cleanup-'.now()->format('Y-m-d-His').'.json';
        $dir = storage_path('app/'.trim($relativeDir, '/\\'));
        $absolute = $dir.DIRECTORY_SEPARATOR.$filename;

        // Không dùng Storage facade — host thiếu ext-fileinfo (`finfo`) sẽ crash mime detector.
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new \RuntimeException('Không tạo được thư mục report: '.$dir);
        }

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (file_put_contents($absolute, $json) === false) {
            throw new \RuntimeException('Không ghi được report: '.$absolute);
        }

        return $absolute;
    }
}
