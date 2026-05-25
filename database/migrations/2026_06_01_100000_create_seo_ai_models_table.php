<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_ai_models', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('api_connection_id')->index();
            $table->string('category', 50)->index()->comment('gemini_pro, gemini_flash, imagen_pro, claude_sonnet...');
            $table->string('raw_model_name', 128)->comment('Slug API: gemini-3-flash-preview, imagen-4.0-fast-generate-001...');
            $table->string('display_name')->comment('Tên hiển thị từ API');
            $table->integer('priority')->default(100)->comment('Ưu tiên phiên bản, cao hơn = chạy trước');
            $table->string('status', 20)->default('active')->comment('active, inactive, exhausted');
            $table->text('last_error')->nullable();
            $table->json('capabilities')->nullable()->comment('supportedGenerationMethods, ...');
            $table->timestamps();

            $table->unique(['api_connection_id', 'raw_model_name'], 'seo_ai_models_connection_raw_unique');
            $table->foreign('api_connection_id')
                ->references('id')
                ->on('api_connections')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_ai_models');
    }
};
