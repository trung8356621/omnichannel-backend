<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique()->index(); // ví dụ: wp-headless, seo-automation
            $table->string('addon_namespace'); // Ví dụ: App\Modules\Seo
            $table->string('db_connection')->default('mysql'); // Tên connection tới DB phụ
            $table->boolean('is_active')->default(true);
            $table->json('config')->nullable(); // Cấu hình global (API Key hệ thống...)
            $table->timestamps();
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};
