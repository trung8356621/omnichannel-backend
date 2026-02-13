<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'wp_headless';

    public function up(): void
    {
        Schema::connection($this->connection)->table('wp_headless_styles', function (Blueprint $table) {
            $table->boolean('external')->default(false)->after('sort_order')
                ->comment('Style từ tên miền khác (CDN, domain ngoài)');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('wp_headless_styles', function (Blueprint $table) {
            $table->dropColumn('external');
        });
    }
};
