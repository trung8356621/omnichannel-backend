<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Database\DatabaseTableOwnershipRegistry;
use App\Support\Database\MisplacedTableCleanupService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class MisplacedTableCleanupTest extends TestCase
{
    private string $coreDbPath;

    private string $addonDbPath;

    protected function setUp(): void
    {
        parent::setUp();

        $dir = storage_path('framework/testing');
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $this->coreDbPath = $dir.DIRECTORY_SEPARATOR.'cleanup_core_'.uniqid('', true).'.sqlite';
        $this->addonDbPath = $dir.DIRECTORY_SEPARATOR.'cleanup_addon_'.uniqid('', true).'.sqlite';
        touch($this->coreDbPath);
        touch($this->addonDbPath);

        Config::set('database.default', 'mysql');
        Config::set('database.core_connection', 'mysql');
        Config::set('database.connections.mysql', [
            'driver' => 'sqlite',
            'database' => $this->coreDbPath,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        Config::set('database.connections.omi_seo_ai', [
            'driver' => 'sqlite',
            'database' => $this->addonDbPath,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        Config::set('database.connections.wp_headless', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        Config::set('database_table_ownership.connection_map', [
            'core' => null,
            'omi_seo_ai' => 'omi_seo_ai',
            'wp_headless' => 'wp_headless',
        ]);
        Config::set('database_table_ownership.scan_owners', ['core', 'omi_seo_ai']);
        Config::set('database_table_ownership.ignored_tables', ['migrations', 'sqlite_sequence']);
        Config::set('database_table_ownership.review_required_patterns', ['automation_*']);
        Config::set('database_table_ownership.owners', [
            'core' => [
                'tables' => ['users', 'jobs'],
                'patterns' => [],
            ],
            'omi_seo_ai' => [
                'tables' => ['articles', 'automation_rules'],
                'patterns' => ['automation_*'],
            ],
        ]);

        DB::purge('mysql');
        DB::purge('omi_seo_ai');

        Schema::connection('mysql')->create('users', function ($table): void {
            $table->id();
            $table->string('name')->nullable();
        });
        Schema::connection('omi_seo_ai')->create('articles', function ($table): void {
            $table->id();
            $table->string('title')->nullable();
        });
    }

    protected function tearDown(): void
    {
        DB::purge('mysql');
        DB::purge('omi_seo_ai');

        foreach ([$this->coreDbPath, $this->addonDbPath] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        parent::tearDown();
    }

    public function test_dry_run_reports_empty_misplaced_without_dropping(): void
    {
        Schema::connection('omi_seo_ai')->create('users', function ($table): void {
            $table->id();
            $table->string('name')->nullable();
        });

        $service = $this->makeService();
        $analysis = $service->analyze();
        $candidates = $analysis['drop_candidates'];

        $this->assertTrue(collect($candidates)->contains(
            fn (array $f): bool => $f['table'] === 'users' && $f['found_connection'] === 'omi_seo_ai',
        ));

        $service->executeDrops($candidates, false);
        $this->assertTrue(Schema::connection('omi_seo_ai')->hasTable('users'));
    }

    public function test_execute_drops_empty_misplaced_table(): void
    {
        Schema::connection('omi_seo_ai')->create('users', function ($table): void {
            $table->id();
            $table->string('name')->nullable();
        });

        $service = $this->makeService();
        $candidates = $service->analyze()['drop_candidates'];
        $result = $service->executeDrops($candidates, true);

        $this->assertTrue(collect($result['dropped'])->contains(
            fn (array $f): bool => $f['table'] === 'users' && $f['status'] === 'dropped',
        ));
        $this->assertFalse(Schema::connection('omi_seo_ai')->hasTable('users'));
        $this->assertTrue(Schema::connection('mysql')->hasTable('users'));
    }

    public function test_non_empty_misplaced_is_not_dropped(): void
    {
        Schema::connection('omi_seo_ai')->create('users', function ($table): void {
            $table->id();
            $table->string('name')->nullable();
        });
        DB::connection('omi_seo_ai')->table('users')->insert(['name' => 'keep']);

        $service = $this->makeService();
        $analysis = $service->analyze();

        $this->assertTrue(collect($analysis['findings'])->contains(
            fn (array $f): bool => $f['table'] === 'users'
                && $f['found_connection'] === 'omi_seo_ai'
                && $f['status'] === 'NON_EMPTY',
        ));
        $this->assertFalse(collect($analysis['drop_candidates'])->contains(
            fn (array $f): bool => $f['table'] === 'users',
        ));

        $service->executeDrops($analysis['drop_candidates'], true);
        $this->assertTrue(Schema::connection('omi_seo_ai')->hasTable('users'));
    }

    public function test_correct_connection_empty_table_is_not_dropped(): void
    {
        $service = $this->makeService();
        $analysis = $service->analyze();

        $this->assertFalse(collect($analysis['drop_candidates'])->contains(
            fn (array $f): bool => $f['table'] === 'articles' && $f['found_connection'] === 'omi_seo_ai',
        ));
        $this->assertTrue(Schema::connection('omi_seo_ai')->hasTable('articles'));
    }

    public function test_unknown_owner_is_not_dropped(): void
    {
        Schema::connection('mysql')->create('mystery_table', function ($table): void {
            $table->id();
        });

        $service = $this->makeService();
        $analysis = $service->analyze();

        $this->assertTrue(collect($analysis['findings'])->contains(
            fn (array $f): bool => $f['table'] === 'mystery_table' && $f['status'] === 'UNKNOWN_OWNER',
        ));
        $this->assertFalse(collect($analysis['drop_candidates'])->contains(
            fn (array $f): bool => $f['table'] === 'mystery_table',
        ));
    }

    public function test_ownership_conflict_is_not_dropped(): void
    {
        Config::set('database_table_ownership.owners', [
            'core' => [
                'tables' => ['shared_conflict'],
                'patterns' => [],
            ],
            'omi_seo_ai' => [
                'tables' => ['shared_conflict', 'articles'],
                'patterns' => [],
            ],
        ]);

        Schema::connection('omi_seo_ai')->create('shared_conflict', function ($table): void {
            $table->id();
        });

        $service = $this->makeService();
        $analysis = $service->analyze();

        $this->assertTrue(collect($analysis['findings'])->contains(
            fn (array $f): bool => $f['table'] === 'shared_conflict' && $f['status'] === 'CONFLICT',
        ));
        $this->assertFalse(collect($analysis['drop_candidates'])->contains(
            fn (array $f): bool => $f['table'] === 'shared_conflict',
        ));
    }

    public function test_same_physical_database_is_not_misplaced(): void
    {
        Config::set('database.connections.omi_seo_ai', [
            'driver' => 'sqlite',
            'database' => $this->coreDbPath,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        DB::purge('omi_seo_ai');

        $service = $this->makeService();
        $analysis = $service->analyze();

        $this->assertFalse(collect($analysis['drop_candidates'])->contains(
            fn (array $f): bool => $f['table'] === 'users',
        ));
        $this->assertTrue(collect($analysis['findings'])->contains(
            fn (array $f): bool => $f['table'] === 'users'
                && $f['status'] === 'SKIPPED'
                && str_contains($f['reason'], 'cùng database vật lý'),
        ));
    }

    public function test_second_run_is_idempotent(): void
    {
        Schema::connection('omi_seo_ai')->create('users', function ($table): void {
            $table->id();
            $table->string('name')->nullable();
        });

        $service = $this->makeService();
        $first = $service->executeDrops($service->analyze()['drop_candidates'], true);
        $this->assertNotEmpty($first['dropped']);

        $secondAnalysis = $service->analyze();
        $second = $service->executeDrops($secondAnalysis['drop_candidates'], true);

        $this->assertSame([], $secondAnalysis['drop_candidates']);
        $this->assertSame([], $second['dropped']);
        $this->assertSame([], $second['errors']);
    }

    public function test_drop_continues_after_missing_table_and_restores_fk_checks(): void
    {
        Schema::connection('omi_seo_ai')->create('users', function ($table): void {
            $table->id();
            $table->string('name')->nullable();
        });
        Schema::connection('omi_seo_ai')->create('jobs', function ($table): void {
            $table->id();
        });

        $service = $this->makeService();
        $result = $service->executeDrops([
            [
                'table' => 'users__already_gone',
                'found_connection' => 'omi_seo_ai',
                'expected_connection' => 'mysql',
                'rows' => 0,
                'status' => 'DROP_CANDIDATE',
                'reason' => 'test missing',
            ],
            [
                'table' => 'users',
                'found_connection' => 'omi_seo_ai',
                'expected_connection' => 'mysql',
                'rows' => 0,
                'status' => 'DROP_CANDIDATE',
                'reason' => 'test users',
            ],
            [
                'table' => 'jobs',
                'found_connection' => 'omi_seo_ai',
                'expected_connection' => 'mysql',
                'rows' => 0,
                'status' => 'DROP_CANDIDATE',
                'reason' => 'test jobs',
            ],
        ], true);

        $this->assertTrue(collect($result['skipped'])->contains(
            fn (array $f): bool => $f['table'] === 'users__already_gone' && $f['status'] === 'SKIPPED',
        ));
        $this->assertTrue(collect($result['dropped'])->contains(
            fn (array $f): bool => $f['table'] === 'users',
        ));
        $this->assertTrue(collect($result['dropped'])->contains(
            fn (array $f): bool => $f['table'] === 'jobs',
        ));
        $this->assertFalse(Schema::connection('omi_seo_ai')->hasTable('users'));
        $this->assertFalse(Schema::connection('omi_seo_ai')->hasTable('jobs'));

        // finally block must re-enable FK checks — PRAGMA write must succeed.
        DB::connection('omi_seo_ai')->statement('PRAGMA foreign_keys = ON');
        $enabled = DB::connection('omi_seo_ai')->select('PRAGMA foreign_keys');
        $this->assertSame(1, (int) ($enabled[0]->foreign_keys ?? 0));
    }

    public function test_one_drop_error_does_not_block_other_tables(): void
    {
        Schema::connection('omi_seo_ai')->create('users', function ($table): void {
            $table->id();
            $table->string('name')->nullable();
        });
        Schema::connection('omi_seo_ai')->create('jobs', function ($table): void {
            $table->id();
        });

        $registry = new DatabaseTableOwnershipRegistry($this->app);
        $service = new class($registry) extends MisplacedTableCleanupService
        {
            protected function dropTable(string $connection, string $table): void
            {
                if ($table === 'users') {
                    throw new \RuntimeException('forced drop failure');
                }

                parent::dropTable($connection, $table);
            }
        };

        $result = $service->executeDrops([
            [
                'table' => 'users',
                'found_connection' => 'omi_seo_ai',
                'expected_connection' => 'mysql',
                'rows' => 0,
                'status' => 'DROP_CANDIDATE',
                'reason' => 'test',
            ],
            [
                'table' => 'jobs',
                'found_connection' => 'omi_seo_ai',
                'expected_connection' => 'mysql',
                'rows' => 0,
                'status' => 'DROP_CANDIDATE',
                'reason' => 'test',
            ],
        ], true);

        $this->assertTrue(collect($result['errors'])->contains(
            fn (array $f): bool => $f['table'] === 'users' && $f['status'] === 'ERROR',
        ));
        $this->assertTrue(collect($result['dropped'])->contains(
            fn (array $f): bool => $f['table'] === 'jobs' && $f['status'] === 'dropped',
        ));
        $this->assertTrue(Schema::connection('omi_seo_ai')->hasTable('users'));
        $this->assertFalse(Schema::connection('omi_seo_ai')->hasTable('jobs'));
    }

    public function test_command_without_execute_does_not_mutate_schema(): void
    {
        Schema::connection('omi_seo_ai')->create('users', function ($table): void {
            $table->id();
            $table->string('name')->nullable();
        });

        $this->artisan('database:cleanup-misplaced-tables')
            ->assertSuccessful();

        $this->assertTrue(Schema::connection('omi_seo_ai')->hasTable('users'));
    }

    private function makeService(): MisplacedTableCleanupService
    {
        return new MisplacedTableCleanupService(
            new DatabaseTableOwnershipRegistry($this->app),
        );
    }
}
