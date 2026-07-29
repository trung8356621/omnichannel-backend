<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Organizational hierarchy:
 * - parent_id already = Owner FK (kept; no duplicate owner_id column)
 * - manager_id = Manager of Staff (nullable)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'manager_id')) {
                $table->unsignedBigInteger('manager_id')->nullable()->after('parent_id')->index();
            }
        });

        // Soft FKs only if users.id exists (core mysql). Avoid hard fail on partial envs.
        try {
            Schema::table('users', function (Blueprint $table): void {
                $table->foreign('manager_id')
                    ->references('id')
                    ->on('users')
                    ->nullOnDelete();
            });
        } catch (Throwable) {
            // Index already enough; FK may already exist or engine mismatch.
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (Schema::hasColumn('users', 'manager_id')) {
                try {
                    $table->dropForeign(['manager_id']);
                } catch (Throwable) {
                    //
                }
                $table->dropColumn('manager_id');
            }
        });
    }
};
