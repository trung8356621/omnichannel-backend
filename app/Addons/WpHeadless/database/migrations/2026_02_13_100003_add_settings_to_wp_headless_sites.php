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
            $table->json('settings')->nullable()->after('public_url')
                ->comment('Toàn bộ settings lấy từ WP: globalStyles, customCss, themeMods, ...');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('wp_headless_sites', function (Blueprint $table) {
            $table->dropColumn('settings');
        });
    }
};
