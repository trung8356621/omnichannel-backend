<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        Schema::connection($this->connection)->table('prompts', function (Blueprint $table) {
            $table->string('name')->nullable()->after('site_id');
            $table->string('type', 64)->nullable()->after('name');
            $table->boolean('is_active')->default(true)->after('type');
        });

        DB::connection($this->connection)->statement(
            'UPDATE prompts SET name = title WHERE name IS NULL OR name = \'\''
        );
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('prompts', function (Blueprint $table) {
            $table->dropColumn(['name', 'type', 'is_active']);
        });
    }
};
