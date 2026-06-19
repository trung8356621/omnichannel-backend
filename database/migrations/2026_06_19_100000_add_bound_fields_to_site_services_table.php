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
        Schema::table('site_services', function (Blueprint $table): void {
            $table->string('bound_type', 10)->default('site')->after('id');
            $table->foreignId('user_id')->nullable()->after('bound_type')->constrained('users')->nullOnDelete();
        });

        DB::table('site_services')->whereNull('bound_type')->update(['bound_type' => 'site']);

        Schema::table('site_services', function (Blueprint $table): void {
            $table->unsignedBigInteger('site_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('site_services', function (Blueprint $table): void {
            $table->dropForeign(['user_id']);
            $table->dropColumn(['bound_type', 'user_id']);
        });
    }
};
