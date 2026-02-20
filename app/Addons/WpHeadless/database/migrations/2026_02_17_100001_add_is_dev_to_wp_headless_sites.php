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
            $table->boolean('is_dev')->default(true)->after('headless_next_dev')
                ->comment('true = dùng domain chính từ bảng sites, false = dùng headless_next_dev');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('wp_headless_sites', function (Blueprint $table) {
            $table->dropColumn('is_dev');
        });
    }
};
