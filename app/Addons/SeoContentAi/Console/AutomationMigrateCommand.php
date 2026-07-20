<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Console;

use App\Addons\SeoContentAi\Automation\BusinessHook\Support\BusinessHookSchemaGuard;
use App\Addons\SeoContentAi\Services\SeoDatabaseConnectionService;
use App\Models\SeoDatabaseConnection;
use Illuminate\Console\Command;

/**
 * Migrate SEO addon via reconciler (tránh FAIL khi bảng cũ đã tồn tại).
 */
final class AutomationMigrateCommand extends Command
{
    protected $signature = 'automation:migrate
        {--connection-id= : SEO database connection id (default: first active)}
        {--only-business-hook : Chỉ chạy migration Business Hook tables}
        {--only-v2 : Chỉ chạy migration Automation V2 graph/scheduler}
        {--only-v3 : Chỉ chạy migration Automation V3 versions/ops}';

    protected $description = 'Run SEO migrations with reconciler, or only Business Hook tables.';

    public function handle(SeoDatabaseConnectionService $connections): int
    {
        if ((bool) $this->option('only-v3')) {
            $this->info('Migrating Automation V3 tables only…');
            $exit = $this->call('migrate', [
                '--database' => 'omi_seo_ai',
                '--path' => 'app/Addons/SeoContentAi/database/migrations/2026_07_20_120000_automation_v3_versions_and_ops.php',
                '--force' => true,
            ]);

            if ($exit !== self::SUCCESS) {
                return $exit;
            }

            $missingTables = BusinessHookSchemaGuard::missingV3Tables();
            $missingColumns = BusinessHookSchemaGuard::missingV3Columns();
            if ($missingTables !== [] || $missingColumns !== []) {
                $this->error('Still missing V3: '.implode(', ', array_merge($missingTables, $missingColumns)));

                return self::FAILURE;
            }

            $this->info('Automation V3 tables ready.');

            return self::SUCCESS;
        }

        if ((bool) $this->option('only-v2')) {
            $this->info('Migrating Automation V2 tables only…');
            $exit = $this->call('migrate', [
                '--database' => 'omi_seo_ai',
                '--path' => 'app/Addons/SeoContentAi/database/migrations/2026_07_20_100000_automation_v2_graph_and_scheduler.php',
                '--force' => true,
            ]);

            if ($exit !== self::SUCCESS) {
                return $exit;
            }

            $missing = BusinessHookSchemaGuard::missingV2Tables();
            if ($missing !== []) {
                $this->error('Still missing V2: '.implode(', ', $missing));

                return self::FAILURE;
            }

            $this->info('Automation V2 tables ready.');

            return self::SUCCESS;
        }

        if ((bool) $this->option('only-business-hook')) {
            $this->info('Migrating Business Hook tables only…');
            $exit = $this->call('migrate', [
                '--database' => 'omi_seo_ai',
                '--path' => 'app/Addons/SeoContentAi/database/migrations/2026_07_19_210000_create_business_hook_tables.php',
                '--force' => true,
            ]);

            if ($exit !== self::SUCCESS) {
                return $exit;
            }

            $missing = BusinessHookSchemaGuard::missingTables();
            if ($missing !== []) {
                $this->error('Still missing: '.implode(', ', $missing));

                return self::FAILURE;
            }

            $this->info('Business Hook tables ready.');

            return self::SUCCESS;
        }

        $query = SeoDatabaseConnection::query()->where('is_active', true)->orderBy('id');
        $connectionId = (int) ($this->option('connection-id') ?? 0);
        if ($connectionId > 0) {
            $query->whereKey($connectionId);
        }

        $connection = $query->first();
        if (! $connection instanceof SeoDatabaseConnection) {
            $this->error('No active SEO database connection found.');

            return self::FAILURE;
        }

        $this->info("Running SEO migrations for connection #{$connection->id} ({$connection->name})…");
        $result = $connections->runMigrationsForConnection($connection);

        $this->line('pending: '.$result['pending']);
        $this->line('executed: '.($result['executed'] ? 'yes' : 'no'));
        $this->line('reconciled: '.$result['reconciled']);

        $missing = BusinessHookSchemaGuard::missingTables();
        if ($missing !== []) {
            $this->warn('Business Hook tables still missing: '.implode(', ', $missing));
            $this->line('Retry with: php artisan automation:migrate --only-business-hook');

            return self::FAILURE;
        }

        $this->info('SEO migrations OK. Business Hook tables present.');

        return self::SUCCESS;
    }
}
