<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Schema automation trên core DB (final shape sau mọi migration SEO legacy).
 * Không sửa migration cũ trên omi_seo_ai — lịch sử production giữ nguyên.
 */
return new class extends Migration
{
    public function up(): void
    {
        $connection = (string) config(
            'automation.target_connection',
            config('database.core_connection', 'mysql')
        );
        $schema = Schema::connection($connection);

        if (! $schema->hasTable('business_events')) {
            $schema->create('business_events', function (Blueprint $table): void {
                $table->id();
                $table->string('event_uuid', 64)->unique();
                $table->string('event_name', 191)->index();
                $table->string('subject_type', 191)->nullable()->index();
                $table->unsignedBigInteger('subject_id')->nullable()->index();
                $table->unsignedBigInteger('site_id')->nullable()->index();
                $table->unsignedBigInteger('project_id')->nullable()->index();
                $table->json('payload')->nullable();
                $table->json('context')->nullable();
                $table->timestamp('occurred_at');
                $table->timestamp('created_at')->useCurrent();
            });
        }

        if (! $schema->hasTable('automation_rules')) {
            $schema->create('automation_rules', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('site_id')->nullable()->index();
                $table->string('code', 191)->unique();
                $table->string('classification', 32)->default('production');
                $table->string('visibility', 16)->default('user');
                $table->string('name', 255);
                $table->text('description')->nullable();
                $table->string('event_name', 191)->index();
                $table->boolean('is_enabled')->default(true)->index();
                $table->boolean('allow_manual_trigger')->default(true);
                $table->integer('priority')->default(100)->index();
                $table->boolean('stop_on_failure')->default(true);
                $table->string('run_mode', 32)->default('queued');
                $table->string('workflow_mode', 16)->default('linear');
                $table->string('trigger_type', 32)->default('event');
                $table->string('schedule_expression', 191)->nullable();
                $table->string('schedule_timezone', 64)->nullable();
                $table->timestamp('next_run_at')->nullable()->index();
                $table->timestamp('last_scheduled_at')->nullable();
                $table->unsignedInteger('version')->default(1);
                $table->unsignedInteger('draft_revision')->default(1);
                $table->unsignedBigInteger('published_version_id')->nullable()->index();
                $table->unsignedBigInteger('draft_version_id')->nullable()->index();
                $table->json('conditions')->nullable();
                $table->json('settings')->nullable();
                $table->json('locale_settings')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();
            });
        }

        if (! $schema->hasTable('automation_rule_actions')) {
            $schema->create('automation_rule_actions', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('automation_rule_id');
                $table->string('action_code', 191)->index();
                $table->unsignedInteger('position');
                $table->boolean('is_enabled')->default(true);
                $table->boolean('continue_on_failure')->default(false);
                $table->unsignedInteger('delay_seconds')->default(0);
                $table->json('input_mapping')->nullable();
                $table->json('settings')->nullable();
                $table->timestamps();

                $table->unique(['automation_rule_id', 'position'], 'automation_rule_actions_rule_position_uq');
                $table->foreign('automation_rule_id', 'automation_rule_actions_rule_fk')
                    ->references('id')
                    ->on('automation_rules')
                    ->cascadeOnDelete();
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
                $table->json('ui_position')->nullable();
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

        if (! $schema->hasTable('automation_rule_versions')) {
            $schema->create('automation_rule_versions', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('automation_rule_id');
                $table->unsignedInteger('version');
                $table->string('status', 32)->index();
                $table->string('workflow_mode', 16)->default('graph');
                $table->string('trigger_type', 32)->default('event');
                $table->string('event_name', 191)->nullable()->index();
                $table->string('schedule_expression', 191)->nullable();
                $table->string('schedule_timezone', 64)->nullable();
                $table->json('conditions')->nullable();
                $table->json('settings')->nullable();
                $table->json('layout')->nullable();
                $table->unsignedInteger('draft_revision')->nullable();
                $table->timestamp('published_at')->nullable();
                $table->unsignedBigInteger('published_by')->nullable();
                $table->timestamps();

                $table->unique(['automation_rule_id', 'version'], 'automation_rule_versions_rule_ver_uq');
                $table->foreign('automation_rule_id', 'automation_rule_versions_rule_fk')
                    ->references('id')->on('automation_rules')->cascadeOnDelete();
            });
        }

        if (! $schema->hasTable('automation_rule_version_nodes')) {
            $schema->create('automation_rule_version_nodes', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('automation_rule_version_id');
                $table->string('node_key', 64);
                $table->string('node_type', 32);
                $table->string('name', 255)->nullable();
                $table->string('action_code', 191)->nullable();
                $table->unsignedInteger('position')->nullable();
                $table->json('config')->nullable();
                $table->json('input_mapping')->nullable();
                $table->json('settings')->nullable();
                $table->json('ui_position')->nullable();
                $table->boolean('is_enabled')->default(true);
                $table->timestamps();

                $table->unique(['automation_rule_version_id', 'node_key'], 'automation_rule_version_nodes_key_uq');
                $table->foreign('automation_rule_version_id', 'automation_rule_version_nodes_ver_fk')
                    ->references('id')->on('automation_rule_versions')->cascadeOnDelete();
            });
        }

        if (! $schema->hasTable('automation_rule_version_edges')) {
            $schema->create('automation_rule_version_edges', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('automation_rule_version_id');
                $table->string('from_node_key', 64);
                $table->string('to_node_key', 64);
                $table->string('branch', 32)->nullable();
                $table->unsignedInteger('priority')->default(100);
                $table->json('condition')->nullable();
                $table->timestamps();

                $table->unique(
                    ['automation_rule_version_id', 'from_node_key', 'to_node_key', 'branch'],
                    'automation_rule_version_edges_path_uq',
                );
                $table->foreign('automation_rule_version_id', 'automation_rule_version_edges_ver_fk')
                    ->references('id')->on('automation_rule_versions')->cascadeOnDelete();
            });
        }

        if (! $schema->hasTable('automation_executions')) {
            $schema->create('automation_executions', function (Blueprint $table): void {
                $table->id();
                $table->uuid('execution_uuid')->unique();
                $table->unsignedBigInteger('business_event_id');
                $table->unsignedBigInteger('automation_rule_id')->nullable();
                $table->unsignedBigInteger('automation_rule_version_id')->nullable()->index();
                $table->unsignedInteger('rule_version')->default(1);
                $table->string('status', 32)->index();
                $table->unsignedInteger('attempt')->default(1);
                $table->string('idempotency_key', 64)->unique();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamp('cancellation_requested_at')->nullable();
                $table->timestamp('heartbeat_at')->nullable();
                $table->string('scheduled_occurrence_key', 64)->nullable()->index();
                $table->string('trigger_type', 32)->default('event')->index();
                $table->unsignedBigInteger('initiated_by_user_id')->nullable()->index();
                $table->string('initiated_from', 191)->nullable()->index();
                $table->string('action_code', 191)->nullable()->index();
                $table->string('error_code', 191)->nullable();
                $table->text('error_message')->nullable();
                $table->json('context')->nullable();
                $table->timestamps();

                $table->foreign('business_event_id', 'automation_executions_event_fk')
                    ->references('id')
                    ->on('business_events')
                    ->cascadeOnDelete();
                $table->foreign('automation_rule_id', 'automation_executions_rule_fk')
                    ->references('id')
                    ->on('automation_rules')
                    ->nullOnDelete();
            });
        }

        if (! $schema->hasTable('automation_action_executions')) {
            $schema->create('automation_action_executions', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('automation_execution_id');
                $table->unsignedBigInteger('automation_rule_action_id')->nullable();
                $table->string('action_code', 191)->index();
                $table->unsignedInteger('position');
                $table->string('status', 32)->index();
                $table->unsignedInteger('attempt')->default(1);
                $table->json('input_snapshot')->nullable();
                $table->json('output_snapshot')->nullable();
                $table->string('error_code', 191)->nullable();
                $table->text('error_message')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();

                $table->unique(['automation_execution_id', 'position'], 'automation_action_exec_position_uq');
                $table->foreign('automation_execution_id', 'automation_action_exec_execution_fk')
                    ->references('id')
                    ->on('automation_executions')
                    ->cascadeOnDelete();
                $table->foreign('automation_rule_action_id', 'automation_action_exec_rule_action_fk')
                    ->references('id')
                    ->on('automation_rule_actions')
                    ->nullOnDelete();
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

        if (! $schema->hasTable('automation_action_runs')) {
            $schema->create('automation_action_runs', function (Blueprint $table): void {
                $table->id();
                $table->uuid('execution_id')->unique();
                $table->uuid('correlation_id')->nullable()->index();
                $table->uuid('causation_id')->nullable();
                $table->string('action_key', 191)->index();
                $table->string('origin', 191)->nullable()->index();
                $table->string('entity_type', 64)->nullable();
                $table->unsignedBigInteger('entity_id')->nullable()->index();
                $table->unsignedBigInteger('team_id')->nullable()->index();
                $table->unsignedBigInteger('site_id')->nullable()->index();
                $table->string('status', 32)->index();
                $table->unsignedInteger('attempt')->default(1);
                $table->string('idempotency_key', 191)->nullable()->index();
                $table->json('input_json')->nullable();
                $table->json('output_json')->nullable();
                $table->json('warning_json')->nullable();
                $table->json('error_json')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();
            });
        }

        if (! $schema->hasTable('automation_scheduler_heartbeats')) {
            $schema->create('automation_scheduler_heartbeats', function (Blueprint $table): void {
                $table->id();
                $table->string('name', 64)->unique();
                $table->timestamp('last_beat_at');
                $table->json('meta')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        $connection = (string) config(
            'automation.target_connection',
            config('database.core_connection', 'mysql')
        );
        $schema = Schema::connection($connection);

        $schema->dropIfExists('automation_node_executions');
        $schema->dropIfExists('automation_action_executions');
        $schema->dropIfExists('automation_executions');
        $schema->dropIfExists('automation_rule_version_edges');
        $schema->dropIfExists('automation_rule_version_nodes');
        $schema->dropIfExists('automation_rule_versions');
        $schema->dropIfExists('automation_rule_edges');
        $schema->dropIfExists('automation_rule_nodes');
        $schema->dropIfExists('automation_rule_actions');
        $schema->dropIfExists('automation_rules');
        $schema->dropIfExists('automation_action_runs');
        $schema->dropIfExists('automation_scheduler_heartbeats');
        $schema->dropIfExists('business_events');
    }
};
