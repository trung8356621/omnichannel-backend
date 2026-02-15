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
            $table->dropColumn('styles');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('wp_headless_templates', function (Blueprint $table) {
            $table->json('styles')->nullable()->after('classes');
        });
    }
};
