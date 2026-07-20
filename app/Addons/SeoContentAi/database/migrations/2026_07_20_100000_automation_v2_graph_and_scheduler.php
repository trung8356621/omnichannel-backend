<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);

        if ($schema->hasTable('automation_rules')) {
            $schema->table('automation_rules', function (Blueprint $table) use ($schema): void {
                if (! $schema->hasColumn('automation_rules', 'workflow_mode')) {
                    $table->string('workflow_mode', 16)->default('linear')->after('run_mode');
                }
                if (! $schema->hasColumn('automation_rules', 'trigger_type')) {
                    $table->string('trigger_type', 32)->default('event')->after('workflow_mode');
                }
                if (! $schema->hasColumn('automation_rules', 'schedule_expression')) {
                    $table->string('schedule_expression', 191)->nullable()->after('trigger_type');
                }
                if (! $schema->hasColumn('automation_rules', 'schedule_timezone')) {
                    $table->string('schedule_timezone', 64)->nullable()->after('schedule_expression');
                }
                if (! $schema->hasColumn('automation_rules', 'next_run_at')) {
                    $table->timestamp('next_run_at')->nullable()->index()->after('schedule_timezone');
                }
                if (! $schema->hasColumn('automation_rules', 'last_scheduled_at')) {
                    $table->timestamp('last_scheduled_at')->nullable()->after('next_run_at');
                }
            });
        }

        if ($schema->hasTable('automation_executions')) {
            $schema->table('automation_executions', function (Blueprint $table) use ($schema): void {
                if (! $schema->hasColumn('automation_executions', 'cancellation_requested_at')) {
                    $table->timestamp('cancellation_requested_at')->nullable()->after('finished_at');
                }
                if (! $schema->hasColumn('automation_executions', 'heartbeat_at')) {
                    $table->timestamp('heartbeat_at')->nullable()->after('cancellation_requested_at');
                }
                if (! $schema->hasColumn('automation_executions', 'scheduled_occurrence_key')) {
                    $table->string('scheduled_occurrence_key', 64)->nullable()->index()->after('heartbeat_at');
                }
            });
        }

        if (! $schema->hasTable('automation_rule_nodes')) {
            $schema->create('automation_rule_nodes', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('automation_rule_id');
                $table->string('node_key', 64);
                $table->string('node_type', 32);
                $table->string('name', 255)->nullable();
                $table->string('action_code', 191)->nullable();
                $table->unsignedInteger('position')->nullable();
                $table->json('config')->nullable();
                $table->json('input_mapping')->nullable();
                $table->json('settings')->nullable();
                $table->boolean('is_enabled')->default(true);
                $table->timestamps();

                $table->unique(['automation_rule_id', 'node_key'], 'automation_rule_nodes_rule_key_uq');
                $table->foreign('automation_rule_id', 'automation_rule_nodes_rule_fk')
                    ->references('id')
                    ->on('automation_rules')
                    ->cascadeOnDelete();
            });
        }

        if (! $schema->hasTable('automation_rule_edges')) {
            $schema->create('automation_rule_edges', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('automation_rule_id');
                $table->string('from_node_key', 64);
                $table->string('to_node_key', 64);
                $table->string('branch', 32)->nullable();
                $table->unsignedInteger('priority')->default(100);
                $table->json('condition')->nullable();
                $table->timestamps();

                $table->unique(
                    ['automation_rule_id', 'from_node_key', 'to_node_key', 'branch'],
                    'automation_rule_edges_path_uq',
                );
                $table->foreign('automation_rule_id', 'automation_rule_edges_rule_fk')
                    ->references('id')
                    ->on('automation_rules')
                    ->cascadeOnDelete();
            });
        }

        if (! $schema->hasTable('automation_node_executions')) {
            $schema->create('automation_node_executions', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('automation_execution_id');
                $table->unsignedBigInteger('automation_rule_node_id')->nullable();
                $table->string('node_key', 64);
                $table->string('node_type', 32);
                $table->string('status', 32)->index();
                $table->unsignedInteger('attempt')->default(1);
                $table->string('idempotency_key', 64)->unique();
                $table->timestamp('available_at')->nullable()->index();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamp('heartbeat_at')->nullable();
                $table->json('input_snapshot')->nullable();
                $table->json('output_snapshot')->nullable();
                $table->string('selected_branch', 32)->nullable();
                $table->string('error_code', 191)->nullable();
                $table->text('error_message')->nullable();
                $table->timestamps();

                $table->foreign('automation_execution_id', 'automation_node_exec_execution_fk')
                    ->references('id')
                    ->on('automation_executions')
                    ->cascadeOnDelete();
                $table->foreign('automation_rule_node_id', 'automation_node_exec_node_fk')
                    ->references('id')
                    ->on('automation_rule_nodes')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);
        $schema->dropIfExists('automation_node_executions');
        $schema->dropIfExists('automation_rule_edges');
        $schema->dropIfExists('automation_rule_nodes');

        if ($schema->hasTable('automation_executions')) {
            $schema->table('automation_executions', function (Blueprint $table): void {
                $table->dropColumn([
                    'cancellation_requested_at',
                    'heartbeat_at',
                    'scheduled_occurrence_key',
                ]);
            });
        }

        if ($schema->hasTable('automation_rules')) {
            $schema->table('automation_rules', function (Blueprint $table): void {
                $table->dropColumn([
                    'workflow_mode',
                    'trigger_type',
                    'schedule_expression',
                    'schedule_timezone',
                    'next_run_at',
                    'last_scheduled_at',
                ]);
            });
        }
    }
};
