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
            $table->string('public_url', 512)->nullable()->after('type');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('wp_headless_sites', function (Blueprint $table) {
            $table->dropColumn('public_url');
        });
    }
};
