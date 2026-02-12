<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'wp_headless';

    public function up(): void
    {
        Schema::connection($this->connection)->create('wp_headless_sites', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary()->comment('Cùng id với bảng sites (main DB), liên kết logic');
            $table->string('type', 64)->comment('flatsome, elementor_based, wp_blocks, unknown');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('wp_headless_sites');
    }
};
