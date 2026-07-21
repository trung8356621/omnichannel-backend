<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection('omi_seo_ai');

        if (! $schema->hasTable('automation_executions')) {
            return;
        }

        try {
            $schema->table('automation_executions', function (Blueprint $table): void {
                $table->dropForeign('automation_executions_rule_fk');
            });
        } catch (\Throwable) {
            // FK may already be absent.
        }

        // Make rule_id nullable without doctrine/dbal change().
        DB::connection('omi_seo_ai')->statement(
            'ALTER TABLE automation_executions MODIFY automation_rule_id BIGINT UNSIGNED NULL'
        );

        $schema->table('automation_executions', function (Blueprint $table) use ($schema): void {
            if (! $schema->hasColumn('automation_executions', 'trigger_type')) {
                $table->string('trigger_type', 32)->default('event')->index();
            }
            if (! $schema->hasColumn('automation_executions', 'initiated_by_user_id')) {
                $table->unsignedBigInteger('initiated_by_user_id')->nullable()->index();
            }
            if (! $schema->hasColumn('automation_executions', 'initiated_from')) {
                $table->string('initiated_from', 191)->nullable()->index();
            }
            if (! $schema->hasColumn('automation_executions', 'action_code')) {
                $table->string('action_code', 191)->nullable()->index();
            }

            $table->foreign('automation_rule_id', 'automation_executions_rule_fk')
                ->references('id')
                ->on('automation_rules')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        $schema = Schema::connection('omi_seo_ai');

        if (! $schema->hasTable('automation_executions')) {
            return;
        }

        $schema->table('automation_executions', function (Blueprint $table) use ($schema): void {
            try {
                $table->dropForeign('automation_executions_rule_fk');
            } catch (\Throwable) {
            }

            foreach (['action_code', 'initiated_from', 'initiated_by_user_id', 'trigger_type'] as $column) {
                if ($schema->hasColumn('automation_executions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
