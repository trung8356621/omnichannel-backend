<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Testing\TestDiscoveryAuditService;
use Illuminate\Console\Command;

final class TestDoctorCommand extends Command
{
    protected $signature = 'test:doctor
        {--json : In kết quả dạng JSON}
        {--skip-list : Bỏ qua bước phpunit --list-tests-xml (chỉ validate file/convention)}';

    protected $description = 'Audit PHPUnit test discovery: tên file, namespace, class, testsuite, runner list';

    public function handle(TestDiscoveryAuditService $service): int
    {
        $this->info('Framework: PHPUnit '.(\PHPUnit\Runner\Version::id()).' (Pest: chưa cài trong project này).');
        $this->line('Đang audit test discovery...');

        $result = $service->audit([
            'run_phpunit_list' => ! (bool) $this->option('skip-list'),
        ]);

        if ((bool) $this->option('json')) {
            $this->line(json_encode([
                'ok' => $result->ok(),
                'pest_available' => $result->pestAvailable,
                'scanned_test_files' => count($result->scannedTestFiles),
                'support_files' => count($result->supportFiles),
                'configured_directories' => $result->configuredDirectories,
                'discovered_classes' => count($result->discoveredClasses),
                'phpunit_list_error' => $result->phpunitListError,
                'issues' => array_map(
                    static fn ($issue) => $issue->toArray(),
                    $result->issues,
                ),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $result->ok() ? self::SUCCESS : self::FAILURE;
        }

        $this->table(
            ['Metric', 'Value'],
            [
                ['Scan roots (*Test.php)', (string) count($result->scannedTestFiles)],
                ['Support/helper PHP', (string) count($result->supportFiles)],
                ['Configured suite dirs', (string) count($result->configuredDirectories)],
                ['Discovered classes', (string) count($result->discoveredClasses)],
                ['Issues', (string) $result->issueCount()],
            ],
        );

        if ($result->configuredDirectories !== []) {
            $this->line('Configured testsuites:');
            foreach ($result->configuredDirectories as $directory) {
                $this->line('  - '.$directory);
            }
        }

        if ($result->issues === []) {
            $this->info('OK: mọi *Test.php hợp lệ đều được discover theo convention.');

            return self::SUCCESS;
        }

        $this->error('Phát hiện '.$result->issueCount().' vấn đề discovery/convention:');
        foreach ($result->issues as $issue) {
            $this->newLine();
            $this->line('<fg=red>['.$issue->code.']</> '.$issue->file);
            $this->line('  Nguyên nhân: '.$issue->message);
            $this->line('  Cách sửa: '.$issue->fix);
        }

        $this->newLine();
        $this->warn('Không dùng `php artisan optimize:clear` để sửa “No tests found”.');
        $this->warn('Xem docs/operations/TESTING.md — chạy `composer test:ci` trước khi coi suite xanh.');

        return self::FAILURE;
    }
}
