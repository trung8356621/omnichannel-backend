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
            if (Schema::connection($this->connection)->hasColumn('wp_headless_sites', 'slug')) {
                $table->dropColumn('slug');
            }
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('wp_headless_sites', function (Blueprint $table) {
            $table->string('slug', 128)->nullable()->unique()->after('id');
        });
    }
};
