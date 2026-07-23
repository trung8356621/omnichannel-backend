<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Automation\AutomationCoreMigrationService;
use App\Support\Automation\AutomationConnection;
use Illuminate\Console\Command;

/**
 * Chuyển automation_* (+ business_events) từ omi_seo_ai sang core DB.
 *
 * Không gộp copy + cutover + drop trong một bước.
 */
final class MigrateAutomationToCoreCommand extends Command
{
    protected $signature = 'automation:migrate-to-core
        {--dry-run : Inventory + mô phỏng copy, không ghi đích}
        {--execute : Copy dữ liệu nguồn → đích (idempotent, không overwrite conflict)}
        {--verify : So sánh count + checksum nguồn/đích}
        {--cleanup-source : Rename (mặc định) hoặc drop bảng nguồn sau verify}
        {--drop : Khi cleanup, DROP thay vì rename legacy (nguy hiểm)}
        {--force : Bắt buộc với cleanup}
        {--phase= : copy|verify|cleanup (alias rõ ràng)}';

    protected $description = 'Migrate automation tables from SEO addon DB to core DB (safe phased).';

    public function handle(AutomationCoreMigrationService $service): int
    {
        $phase = $this->resolvePhase();

        $this->info('Automation DB migration');
        $this->line('source: '.AutomationConnection::source());
        $this->line('target: '.AutomationConnection::target());
        $this->line('runtime: '.AutomationConnection::name());
        $this->line('phase: '.$phase);
        $this->newLine();

        $report = match ($phase) {
            'dry-run' => $service->copy(execute: false),
            'copy' => $service->copy(execute: true),
            'verify' => $service->verify(),
            'cleanup' => $service->cleanupSource(
                force: (bool) $this->option('force'),
                renameOnly: ! (bool) $this->option('drop'),
            ),
            default => null,
        };

        if ($report === null) {
            $this->error('Chọn một: --dry-run | --execute | --verify | --cleanup-source | --phase=');

            return self::FAILURE;
        }

        $this->renderReport($report);

        $path = storage_path('app/'.trim((string) config('automation.report_directory'), '/\\'));
        $this->comment("Report JSON: {$path}");

        if (($report['errors'] ?? []) !== []) {
            return self::FAILURE;
        }

        if ($phase === 'verify' && ! ($report['cutover_ready'] ?? false)) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function resolvePhase(): string
    {
        $explicit = $this->option('phase');
        if (is_string($explicit) && $explicit !== '') {
            return match ($explicit) {
                'copy' => 'copy',
                'verify' => 'verify',
                'cleanup' => 'cleanup',
                'dry-run', 'dryrun' => 'dry-run',
                default => $explicit,
            };
        }

        if ($this->option('cleanup-source')) {
            return 'cleanup';
        }
        if ($this->option('verify')) {
            return 'verify';
        }
        if ($this->option('execute')) {
            return 'copy';
        }
        if ($this->option('dry-run')) {
            return 'dry-run';
        }

        return 'help';
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function renderReport(array $report): void
    {
        foreach ($report['tables'] as $table => $info) {
            if (! is_array($info)) {
                continue;
            }
            $status = (string) ($info['status'] ?? '?');
            $src = $info['source_count'] ?? '-';
            $tgt = $info['target_count'] ?? ($info['target_count_before'] ?? '-');
            $extra = '';
            if (isset($info['copied'])) {
                $extra = sprintf(
                    ' copied=%d present=%d conflict=%d',
                    $info['copied'],
                    $info['already_present'] ?? 0,
                    $info['conflicts'] ?? 0
                );
            }
            if (array_key_exists('checksum_match', $info)) {
                $extra .= ' checksum='.($info['checksum_match'] ? 'ok' : 'FAIL');
            }
            if (isset($info['first_diff']) && is_array($info['first_diff'])) {
                $cols = implode(',', $info['first_diff']['columns'] ?? []);
                $extra .= " first_diff_id={$info['first_diff']['id']} cols={$cols}";
            }
            $this->line("[{$status}] {$table} source={$src} target={$tgt}{$extra}");
        }

        foreach ($report['conflicts'] as $conflict) {
            if (is_array($conflict)) {
                $this->warn('CONFLICT '.$conflict['table'].'#'.$conflict['id']);
            }
        }

        foreach ($report['errors'] as $error) {
            $this->error((string) $error);
        }

        $this->newLine();
        $this->info('cutover_ready: '.(($report['cutover_ready'] ?? false) ? 'YES' : 'NO'));
        if (($report['post_cutover_source_absent'] ?? false) === true) {
            $this->comment('Post-cutover: source tables absent — data đang ở target/runtime. Smoke test thay vì so nguồn.');
        }
        foreach ($report['notes'] ?? [] as $note) {
            $this->comment((string) $note);
        }
    }
}
