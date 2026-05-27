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
        Schema::connection($this->connection)->table('seo_media', function (Blueprint $table): void {
            if (! Schema::connection($this->connection)->hasColumn('seo_media', 'prompt_id')) {
                $table->unsignedBigInteger('prompt_id')->nullable()->index()->after('article_id');
            }

            if (! Schema::connection($this->connection)->hasColumn('seo_media', 'prompt_variables')) {
                $table->json('prompt_variables')->nullable()->after('prompt_id');
            }

            if (! Schema::connection($this->connection)->hasColumn('seo_media', 'editor_block_id')) {
                $table->string('editor_block_id', 64)->nullable()->index()->after('prompt_variables');
            }
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('seo_media', function (Blueprint $table): void {
            if (Schema::connection($this->connection)->hasColumn('seo_media', 'editor_block_id')) {
                $table->dropColumn('editor_block_id');
            }

            if (Schema::connection($this->connection)->hasColumn('seo_media', 'prompt_variables')) {
                $table->dropColumn('prompt_variables');
            }

            if (Schema::connection($this->connection)->hasColumn('seo_media', 'prompt_id')) {
                $table->dropColumn('prompt_id');
            }
        });
    }
};
