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
            $table->string('template_path', 128)->nullable()->after('type')
                ->comment('Tên file template (post_type/taxonomy): page, single, category, archive, ...');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('wp_headless_templates', function (Blueprint $table) {
            $table->dropColumn('template_path');
        });
    }
};
