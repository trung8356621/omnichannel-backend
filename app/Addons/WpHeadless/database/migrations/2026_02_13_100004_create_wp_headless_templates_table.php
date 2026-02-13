<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'wp_headless';

    public function up(): void
    {
        Schema::connection($this->connection)->create('wp_headless_templates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('site_id')->comment('id wp_headless_sites ( = main sites.id)');
            $table->string('type', 32)->comment('header, footer, ...');
            $table->longText('template')->comment('HTML template');
            $table->json('classes')->nullable()->comment('Toàn bộ class bóc tách từ template');
            $table->json('styles')->nullable()->comment('Inline styles bóc tách từ template');
            $table->timestamps();

            $table->unique(['site_id', 'type']);
            $table->index('site_id');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('wp_headless_templates');
    }
};
