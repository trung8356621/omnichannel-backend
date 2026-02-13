<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'wp_headless';

    public function up(): void
    {
        Schema::connection($this->connection)->create('wp_headless_styles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('site_id')->comment('id wp_headless_sites ( = main sites.id)');
            $table->string('post_type', 64)->comment('global, post, page, product, ...');
            $table->string('style_type', 16)->comment('file | inline | font');
            $table->string('name')->nullable();
            $table->string('url', 1024)->nullable()->comment('URL file CSS khi style_type=file');
            $table->longText('content')->nullable()->comment('Nội dung CSS khi style_type=inline');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['site_id', 'post_type']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('wp_headless_styles');
    }
};
