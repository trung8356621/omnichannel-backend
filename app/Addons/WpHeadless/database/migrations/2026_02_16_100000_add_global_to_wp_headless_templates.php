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
            $table->boolean('global')->default(false)->after('type')
                ->comment('true = template không phải post_type/taxonomy (header, footer, sidebar, ...)');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('wp_headless_templates', function (Blueprint $table) {
            $table->dropColumn('global');
        });
    }
};
