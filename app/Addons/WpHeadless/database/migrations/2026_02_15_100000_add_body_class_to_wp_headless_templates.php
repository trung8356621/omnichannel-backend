<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'wp_headless';

    public function up(): void
    {
        Schema::connection($this->connection)->table('wp_headless_templates', function (Blueprint $table) {
            $table->json('body_class')->nullable()->after('styles')->comment('Body classes từ WordPress get_body_class() cho post_type/taxonomy');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('wp_headless_templates', function (Blueprint $table) {
            $table->dropColumn('body_class');
        });
    }
};
