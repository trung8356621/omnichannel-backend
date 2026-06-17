<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_database_connections', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('hash_id', 64)->unique();
            $table->enum('type', ['auto', 'manual'])->default('auto');
            $table->string('host')->nullable();
            $table->string('port')->nullable();
            $table->string('database')->nullable();
            $table->string('username')->nullable();
            $table->text('password')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('hash_id');
        });

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

        if (! Schema::hasTable('sites')) {
            return;
        }

        $legacyDatabase = (string) env('SEO_CONTENT_AI_LEGACY_DB', 'omi_seo_ai');
        $hashId = Str::random(32);
        $now = now();

        $connectionId = DB::table('seo_database_connections')->insertGetId([
            'name' => 'Legacy Shared ('.$legacyDatabase.')',
            'hash_id' => $hashId,
            'type' => 'auto',
            'host' => null,
            'port' => null,
            'database' => $legacyDatabase,
            'username' => null,
            'password' => null,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $siteIds = DB::table('sites')->pluck('id');
        foreach ($siteIds as $siteId) {
            DB::table('seo_connection_sites')->insert([
                'connection_id' => $connectionId,
                'site_id' => $siteId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_connection_sites');
        Schema::dropIfExists('seo_database_connections');
    }
};
