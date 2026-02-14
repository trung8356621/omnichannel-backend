<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'wp_headless';

    public function up(): void
    {
        Schema::connection($this->connection)->create('wp_headless_styles_optimized', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('site_id')->comment('id wp_headless_sites ( = main sites.id)');
            $table->string('post_type', 64)->comment('global, post, page, header, ... trùng wp_headless_templates.type');
            $table->unsignedSmallInteger('chunk_index')->default(0)->comment('0 = file duy nhất, >0 khi đã tách nhiều file');
            $table->longText('content')->comment('CSS đã tối ưu (chỉ rules dùng bởi template classes)');
            $table->unsignedInteger('size')->default(0)->comment('Độ dài content (bytes)');
            $table->timestamps();

            $table->index(['site_id', 'post_type']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('wp_headless_styles_optimized');
    }
};
