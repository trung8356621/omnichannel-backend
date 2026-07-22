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

        if (! $schema->hasTable('business_events')) {
            $schema->create('business_events', function (Blueprint $table): void {
                $table->id();
                // SHA-256 sync_operation_id (64) dùng làm event_uuid cho wordpress.synced dedupe.
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
                $table->string('code', 191)->unique();
                $table->string('name', 255);
                $table->text('description')->nullable();
                $table->string('event_name', 191)->index();
                $table->boolean('is_enabled')->default(true)->index();
                $table->integer('priority')->default(100)->index();
                $table->boolean('stop_on_failure')->default(true);
                $table->string('run_mode', 32)->default('queued');
                $table->unsignedInteger('version')->default(1);
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

        if (! $schema->hasTable('automation_executions')) {
            $schema->create('automation_executions', function (Blueprint $table): void {
                $table->id();
                $table->uuid('execution_uuid')->unique();
                $table->unsignedBigInteger('business_event_id');
                $table->unsignedBigInteger('automation_rule_id');
                $table->unsignedInteger('rule_version')->default(1);
                $table->string('status', 32)->index();
                $table->unsignedInteger('attempt')->default(1);
                $table->string('idempotency_key', 64)->unique();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
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
                    ->cascadeOnDelete();
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
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);
        $schema->dropIfExists('automation_action_executions');
        $schema->dropIfExists('automation_executions');
        $schema->dropIfExists('automation_rule_actions');
        $schema->dropIfExists('automation_rules');
        $schema->dropIfExists('business_events');
    }
};
