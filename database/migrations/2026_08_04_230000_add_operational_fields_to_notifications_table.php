<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('notifications')) {
            return;
        }

        Schema::table('notifications', function (Blueprint $table): void {
            if (! Schema::hasColumn('notifications', 'event_code')) {
                $table->string('event_code', 64)->nullable()->after('type');
            }
            if (! Schema::hasColumn('notifications', 'severity')) {
                $table->string('severity', 16)->nullable()->after('event_code');
            }
            if (! Schema::hasColumn('notifications', 'dedup_key')) {
                $table->string('dedup_key', 191)->nullable()->after('severity');
            }
            if (! Schema::hasColumn('notifications', 'group_key')) {
                $table->string('group_key', 191)->nullable()->after('dedup_key');
            }
            if (! Schema::hasColumn('notifications', 'occurrence_count')) {
                $table->unsignedInteger('occurrence_count')->default(1)->after('group_key');
            }
            if (! Schema::hasColumn('notifications', 'first_occurred_at')) {
                $table->timestamp('first_occurred_at')->nullable()->after('occurrence_count');
            }
            if (! Schema::hasColumn('notifications', 'last_occurred_at')) {
                $table->timestamp('last_occurred_at')->nullable()->after('first_occurred_at');
            }
            if (! Schema::hasColumn('notifications', 'resolved_at')) {
                $table->timestamp('resolved_at')->nullable()->after('last_occurred_at');
            }
        });

        Schema::table('notifications', function (Blueprint $table): void {
            $indexes = $this->indexNames('notifications');
            if (! in_array('notifications_dedup_active_idx', $indexes, true)) {
                $table->index(['dedup_key', 'resolved_at', 'notifiable_type', 'notifiable_id'], 'notifications_dedup_active_idx');
            }
            if (! in_array('notifications_unread_ops_idx', $indexes, true)) {
                $table->index(['notifiable_type', 'notifiable_id', 'read_at', 'resolved_at'], 'notifications_unread_ops_idx');
            }
            if (! in_array('notifications_event_code_idx', $indexes, true)) {
                $table->index(['event_code', 'resolved_at'], 'notifications_event_code_idx');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('notifications')) {
            return;
        }

        Schema::table('notifications', function (Blueprint $table): void {
            $indexes = $this->indexNames('notifications');
            foreach (['notifications_dedup_active_idx', 'notifications_unread_ops_idx', 'notifications_event_code_idx'] as $index) {
                if (in_array($index, $indexes, true)) {
                    $table->dropIndex($index);
                }
            }

            $columns = [];
            foreach ([
                'event_code',
                'severity',
                'dedup_key',
                'group_key',
                'occurrence_count',
                'first_occurred_at',
                'last_occurred_at',
                'resolved_at',
            ] as $column) {
                if (Schema::hasColumn('notifications', $column)) {
                    $columns[] = $column;
                }
            }
            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }

    /**
     * @return list<string>
     */
    private function indexNames(string $table): array
    {
        try {
            $rows = Schema::getConnection()->select('SHOW INDEX FROM `'.$table.'`');
            $names = [];
            foreach ($rows as $row) {
                $name = (string) ($row->Key_name ?? '');
                if ($name !== '') {
                    $names[$name] = true;
                }
            }

            return array_keys($names);
        } catch (\Throwable) {
            return [];
        }
    }
};
