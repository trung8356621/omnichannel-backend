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
        Schema::create('service_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->string('name'); // Gói Basic, Gói Pro...
            $table->text('description')->nullable();
            $table->decimal('price', 15, 2);
            $table->integer('duration_days')->default(30); // Thời hạn gói
            $table->json('limits')->nullable(); // Ví dụ: {"max_sites": 3, "api_calls": 1000}
            $table->timestamps();
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('service_plans');
    }
};
