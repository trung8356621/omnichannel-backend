<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'wp_headless';

    public function up(): void
    {
        Schema::connection($this->connection)->table('wp_headless_templates', function (Blueprint $table) {
            $table->json('ids')->nullable()->after('classes')->comment('Id HTML bóc từ template JSON (tối ưu CSS / audit)');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('wp_headless_templates', function (Blueprint $table) {
            $table->dropColumn('ids');
        });
    }
};
