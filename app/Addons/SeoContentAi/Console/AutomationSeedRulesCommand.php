<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Console;

use App\Addons\SeoContentAi\Automation\BusinessHook\Seed\AutomationDefaultRulesSeeder;
use App\Addons\SeoContentAi\Automation\BusinessHook\Support\BusinessHookSchemaGuard;
use Illuminate\Console\Command;

final class AutomationSeedRulesCommand extends Command
{
    protected $signature = 'automation:seed-rules';

    protected $description = 'Seed default automation rules via AutomationDefaultRulesSeeder.';

    public function handle(AutomationDefaultRulesSeeder $seeder): int
    {
        $missing = BusinessHookSchemaGuard::missingTables();
        if ($missing !== []) {
            $this->error('Business Hook tables missing: '.implode(', ', $missing));
            $this->line('Run SEO migrations first:');
            $this->line('  '.BusinessHookSchemaGuard::migrateHint());
            $this->line('Or Admin → SEO Database Connections → Run migrations.');

            return self::FAILURE;
        }

        $seeder->seed();
        $this->info('Default automation rules seeded (missing codes only; all disabled).');

        return self::SUCCESS;
    }
}
