<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'wp_headless';

    public function up(): void
    {
        Schema::connection($this->connection)->table('wp_headless_sites', function (Blueprint $table) {
            $table->string('headless_next_dev', 512)->nullable()->after('public_url')
                ->comment('Domain Next.js khi chạy dev (VD: http://localhost:3000)');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('wp_headless_sites', function (Blueprint $table) {
            $table->dropColumn('headless_next_dev');
        });
    }
};
