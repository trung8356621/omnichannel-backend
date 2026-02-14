<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'wp_headless';

    public function up(): void
    {
        Schema::connection($this->connection)->table('wp_headless_styles_optimized', function (Blueprint $table) {
            $table->string('path', 512)->nullable()->after('chunk_index')->comment('Đường dẫn file CSS public, ví dụ wp-headless/1/page-0.css');
            $table->longText('content')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('wp_headless_styles_optimized', function (Blueprint $table) {
            $table->dropColumn('path');
            $table->longText('content')->nullable(false)->change();
        });
    }
};
