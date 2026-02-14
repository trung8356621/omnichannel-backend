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
            $table->unsignedBigInteger('parent_id')->nullable()->after('site_id')->comment('Trùng CSS: trỏ tới bản ghi gốc (cùng url hoặc content)');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('wp_headless_styles', function (Blueprint $table) {
            $table->dropColumn('parent_id');
        });
    }
};
