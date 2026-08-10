<?php

declare(strict_types=1);

namespace Tests\Unit;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Jobs\ExecuteAutomationRuleJob;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\AutomationRule;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\AutomationSchedulerHeartbeat;
use Omnichannel\Addons\Agent\Automation\Exceptions\AutomationException;
use App\Services\Automation\AutomationCoreMigrationService;
use App\Support\Automation\AutomationConnection;
use App\Support\Automation\AutomationModel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class AutomationCoreMigrationTest extends TestCase
{
    private string $source = 'automation_test_source';

    private string $target = 'automation_test_target';

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('database.connections.'.$this->source, [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        Config::set('database.connections.'.$this->target, [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        Config::set('automation.source_connection', $this->source);
        Config::set('automation.target_connection', $this->target);
        Config::set('automation.connection', $this->source);
        Config::set('automation.chunk_size', 2);
        Config::set('automation.report_directory', 'automation-migration-test');

        $reportDir = storage_path('app/automation-migration-test');
        if (is_dir($reportDir)) {
            foreach (glob($reportDir.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        } else {
            mkdir($reportDir, 0755, true);
        }

        $this->createMinimalSchema($this->source);
        $this->createMinimalSchema($this->target);
    }

    public function test_model_resolves_connection_from_config(): void
    {
        Config::set('automation.connection', $this->target);
        $model = new AutomationRule;
        $this->assertSame($this->target, $model->getConnectionName());
        $this->assertInstanceOf(AutomationModel::class, $model);
    }

    public function test_core_schema_exists_on_target_not_required_on_fresh_seo_noop(): void
    {
        $this->assertTrue(Schema::connection($this->target)->hasTable('automation_rules'));
        $this->assertTrue(Schema::connection($this->target)->hasTable('business_events'));
    }

    public function test_copy_preserves_id_and_timestamps_idempotent(): void
    {
        $createdAt = '2026-01-15 10:00:00';
        DB::connection($this->source)->table('automation_rules')->insert([
            'id' => 42,
            'code' => 'rule-a',
            'name' => 'Rule A',
            'event_name' => 'article.approved',
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        $service = app(AutomationCoreMigrationService::class);
        $first = $service->copy(execute: true);
        $this->assertSame(1, $first['tables']['automation_rules']['copied']);

        $row = DB::connection($this->target)->table('automation_rules')->where('id', 42)->first();
        $this->assertNotNull($row);
        $this->assertSame($createdAt, (string) $row->created_at);

        $second = $service->copy(execute: true);
        $this->assertSame(0, $second['tables']['automation_rules']['copied']);
        $this->assertSame(1, $second['tables']['automation_rules']['already_present']);
        $this->assertSame(1, (int) DB::connection($this->target)->table('automation_rules')->count());
    }

    public function test_copy_skips_identical_and_reports_conflict_without_overwrite(): void
    {
        DB::connection($this->source)->table('automation_rules')->insert([
            'id' => 1,
            'code' => 'same',
            'name' => 'Same',
            'event_name' => 'e',
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);
        DB::connection($this->source)->table('automation_rules')->insert([
            'id' => 2,
            'code' => 'conflict',
            'name' => 'Source Name',
            'event_name' => 'e',
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);

        DB::connection($this->target)->table('automation_rules')->insert([
            'id' => 1,
            'code' => 'same',
            'name' => 'Same',
            'event_name' => 'e',
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);
        DB::connection($this->target)->table('automation_rules')->insert([
            'id' => 2,
            'code' => 'conflict',
            'name' => 'Target Different',
            'event_name' => 'e',
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);

        $report = app(AutomationCoreMigrationService::class)->copy(execute: true);
        $this->assertSame(1, $report['tables']['automation_rules']['already_present']);
        $this->assertSame(1, $report['tables']['automation_rules']['conflicts']);
        $this->assertSame(
            'Target Different',
            DB::connection($this->target)->table('automation_rules')->where('id', 2)->value('name')
        );
    }

    public function test_chunk_copy_works(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            DB::connection($this->source)->table('automation_rules')->insert([
                'id' => $i,
                'code' => "r{$i}",
                'name' => "N{$i}",
                'event_name' => 'e',
                'created_at' => '2026-01-01 00:00:00',
                'updated_at' => '2026-01-01 00:00:00',
            ]);
        }

        $report = app(AutomationCoreMigrationService::class)->copy(execute: true);
        $this->assertSame(5, $report['tables']['automation_rules']['copied']);
        $this->assertSame(5, (int) DB::connection($this->target)->table('automation_rules')->count());
    }

    public function test_verify_detects_count_and_checksum_mismatch(): void
    {
        DB::connection($this->source)->table('automation_scheduler_heartbeats')->insert([
            'id' => 1,
            'name' => 'dispatch',
            'last_beat_at' => '2026-01-01 00:00:00',
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);
        DB::connection($this->target)->table('automation_scheduler_heartbeats')->insert([
            'id' => 1,
            'name' => 'dispatch',
            'last_beat_at' => '2026-01-02 00:00:00',
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);

        $report = app(AutomationCoreMigrationService::class)->verify();
        $this->assertFalse($report['cutover_ready']);
        $this->assertFalse($report['tables']['automation_scheduler_heartbeats']['checksum_match']);
        $this->assertSame(1, $report['tables']['automation_scheduler_heartbeats']['first_diff']['id'] ?? null);
        $this->assertContains('last_beat_at', $report['tables']['automation_scheduler_heartbeats']['first_diff']['columns'] ?? []);
    }

    public function test_verify_treats_reordered_json_as_equal(): void
    {
        Schema::connection($this->source)->table('automation_rules', function (Blueprint $table): void {
            $table->json('settings')->nullable();
        });
        Schema::connection($this->target)->table('automation_rules', function (Blueprint $table): void {
            $table->json('settings')->nullable();
        });

        DB::connection($this->source)->table('automation_rules')->insert([
            'id' => 1,
            'code' => 'j',
            'name' => 'J',
            'event_name' => 'e',
            'settings' => '{"b":2,"a":1}',
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);
        DB::connection($this->target)->table('automation_rules')->insert([
            'id' => 1,
            'code' => 'j',
            'name' => 'J',
            'event_name' => 'e',
            'settings' => '{"a":1,"b":2}',
            'created_at' => '2026-01-01 00:00:00.000000',
            'updated_at' => '2026-01-01 00:00:00',
        ]);

        $report = app(AutomationCoreMigrationService::class)->verify();
        $this->assertTrue($report['tables']['automation_rules']['checksum_match']);
    }

    public function test_cutover_and_rollback_via_config(): void
    {
        Config::set('automation.connection', $this->target);
        $this->assertSame($this->target, AutomationConnection::name());
        $this->assertSame($this->target, (new AutomationSchedulerHeartbeat)->getConnectionName());

        Config::set('automation.connection', $this->source);
        $this->assertSame($this->source, AutomationConnection::name());
        $this->assertSame($this->source, (new AutomationSchedulerHeartbeat)->getConnectionName());
    }

    public function test_job_serializes_id_only_and_queries_current_connection(): void
    {
        $job = new ExecuteAutomationRuleJob(99);
        $this->assertSame(99, $job->automationExecutionId);
        $serialized = serialize($job);
        $this->assertStringNotContainsString('omi_seo_ai', $serialized);
        $this->assertStringContainsString('automationExecutionId', $serialized);
    }

    public function test_cleanup_blocked_without_verify_and_without_force(): void
    {
        $verifyPath = storage_path('app/automation-migration-test/latest-verify.json');
        if (is_file($verifyPath)) {
            unlink($verifyPath);
        }

        $service = app(AutomationCoreMigrationService::class);
        $blocked = $service->cleanupSource(force: false);
        $this->assertNotEmpty($blocked['errors']);

        $still = $service->cleanupSource(force: true);
        $this->assertTrue(
            collect($still['errors'])->contains(fn ($e) => str_contains((string) $e, 'verify')),
            'Expected cleanup block message about verify, got: '.json_encode($still['errors'])
        );
    }

    public function test_handler_missing_exception_is_explicit(): void
    {
        $e = AutomationException::handlerMissing('seo.wordpress.sync');
        $this->assertSame('handler_missing', $e->errorCode);
        $this->assertStringContainsString('seo.wordpress.sync', $e->getMessage());
    }

    public function test_foreign_key_checks_restored_after_cleanup_error(): void
    {
        // SQLite: cleanup rename path; ensure finally does not leave connection broken.
        Config::set('automation.connection', $this->target);
        file_put_contents(
            storage_path('app/automation-migration-test/latest-verify.json'),
            json_encode(['verified' => true, 'cutover_ready' => true])
        );

        DB::connection($this->source)->table('automation_rules')->insert([
            'id' => 1,
            'code' => 'x',
            'name' => 'x',
            'event_name' => 'e',
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);

        $report = app(AutomationCoreMigrationService::class)->cleanupSource(force: true, renameOnly: true);
        $this->assertSame('RENAMED', $report['tables']['automation_rules']['status'] ?? null);
        $this->assertFalse(Schema::connection($this->source)->hasTable('automation_rules'));
    }

    private function createMinimalSchema(string $connection): void
    {
        $schema = Schema::connection($connection);

        $schema->create('business_events', function (Blueprint $table): void {
            $table->id();
            $table->string('event_uuid', 64)->unique();
            $table->string('event_name', 191);
            $table->timestamp('occurred_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        $schema->create('automation_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 191)->unique();
            $table->string('name', 255);
            $table->string('event_name', 191);
            $table->timestamps();
        });

        foreach ([
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
        ] as $table) {
            if (! $schema->hasTable($table)) {
                $schema->create($table, function (Blueprint $blueprint) use ($table): void {
                    $blueprint->id();
                    if ($table === 'automation_action_runs') {
                        $blueprint->string('execution_id')->nullable();
                        $blueprint->string('action_key')->nullable();
                        $blueprint->string('status')->nullable();
                    }
                    $blueprint->timestamps();
                });
            }
        }

        $schema->create('automation_scheduler_heartbeats', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 64)->unique();
            $table->timestamp('last_beat_at');
            $table->timestamps();
        });
    }
}
