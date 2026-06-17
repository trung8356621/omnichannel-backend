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
        Schema::create('seo_connection_users', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('connection_id')
                ->constrained('seo_database_connections')
                ->cascadeOnDelete();
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['connection_id', 'user_id']);
        });

        if (Schema::hasTable('seo_connection_sites') && Schema::hasTable('sites')) {
            $pairs = DB::table('seo_connection_sites')
                ->join('sites', 'sites.id', '=', 'seo_connection_sites.site_id')
                ->select('seo_connection_sites.connection_id', 'sites.user_id')
                ->distinct()
                ->get();

            $now = now();
            foreach ($pairs as $pair) {
                $userId = (int) $pair->user_id;
                $connectionId = (int) $pair->connection_id;

                if ($userId <= 0 || $connectionId <= 0) {
                    continue;
                }

                DB::table('seo_connection_users')->insertOrIgnore([
                    'connection_id' => $connectionId,
                    'user_id' => $userId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            Schema::dropIfExists('seo_connection_sites');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_connection_users');

        if (! Schema::hasTable('seo_database_connections') || Schema::hasTable('seo_connection_sites')) {
            return;
        }

        Schema::create('seo_connection_sites', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('connection_id')
                ->constrained('seo_database_connections')
                ->cascadeOnDelete();
            $table->foreignId('site_id')
                ->constrained('sites')
                ->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['connection_id', 'site_id']);
        });
    }
};
