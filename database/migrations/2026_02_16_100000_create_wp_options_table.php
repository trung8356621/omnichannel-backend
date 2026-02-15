<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wp_options', function (Blueprint $table) {
            $table->id();
            $table->string('option_name', 191)->unique()->comment('Tên option (như WordPress)');
            $table->longText('option_value')->nullable()->comment('Giá trị (string hoặc serialized)');
            $table->string('autoload', 20)->default('yes')->comment('yes | no');
            $table->timestamps();

            $table->index('autoload');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wp_options');
    }
};
