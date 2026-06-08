<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        Schema::connection($this->connection)->table('seo_project_tasks', function (Blueprint $table): void {
            $table->dropIndex(['article_id']);
            $table->unique('article_id');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('seo_project_tasks', function (Blueprint $table): void {
            $table->dropUnique(['article_id']);
            $table->index('article_id');
        });
    }
};
