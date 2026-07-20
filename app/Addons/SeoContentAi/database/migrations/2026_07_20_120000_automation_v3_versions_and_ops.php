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
                if (! $schema->hasColumn('automation_rules', 'site_id')) {
                    $table->unsignedBigInteger('site_id')->nullable()->index()->after('id');
                }
                if (! $schema->hasColumn('automation_rules', 'draft_revision')) {
                    $table->unsignedInteger('draft_revision')->default(1)->after('version');
                }
                if (! $schema->hasColumn('automation_rules', 'published_version_id')) {
                    $table->unsignedBigInteger('published_version_id')->nullable()->index()->after('draft_revision');
                }
                if (! $schema->hasColumn('automation_rules', 'draft_version_id')) {
                    $table->unsignedBigInteger('draft_version_id')->nullable()->index()->after('published_version_id');
                }
            });
        }

        if ($schema->hasTable('automation_rule_nodes') && ! $schema->hasColumn('automation_rule_nodes', 'ui_position')) {
            $schema->table('automation_rule_nodes', function (Blueprint $table): void {
                $table->json('ui_position')->nullable()->after('settings');
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

        if ($schema->hasTable('automation_executions') && ! $schema->hasColumn('automation_executions', 'automation_rule_version_id')) {
            $schema->table('automation_executions', function (Blueprint $table): void {
                $table->unsignedBigInteger('automation_rule_version_id')->nullable()->index()->after('automation_rule_id');
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
        $schema = Schema::connection($this->connection);
        $schema->dropIfExists('automation_scheduler_heartbeats');
        $schema->dropIfExists('automation_rule_version_edges');
        $schema->dropIfExists('automation_rule_version_nodes');
        $schema->dropIfExists('automation_rule_versions');

        if ($schema->hasTable('automation_executions') && $schema->hasColumn('automation_executions', 'automation_rule_version_id')) {
            $schema->table('automation_executions', function (Blueprint $table): void {
                $table->dropColumn('automation_rule_version_id');
            });
        }

        if ($schema->hasTable('automation_rule_nodes') && $schema->hasColumn('automation_rule_nodes', 'ui_position')) {
            $schema->table('automation_rule_nodes', function (Blueprint $table): void {
                $table->dropColumn('ui_position');
            });
        }

        if ($schema->hasTable('automation_rules')) {
            $schema->table('automation_rules', function (Blueprint $table) use ($schema): void {
                foreach (['site_id', 'draft_revision', 'published_version_id', 'draft_version_id'] as $col) {
                    if ($schema->hasColumn('automation_rules', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
};
