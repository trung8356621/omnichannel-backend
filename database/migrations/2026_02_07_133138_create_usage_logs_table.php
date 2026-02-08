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
        // Bảng USAGE_LOGS: Kiểm soát hạn mức sử dụng của gói cước
        Schema::create('usage_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->string('metric_key')->index(); // Ví dụ: 'api_calls', 'sites_count'
            $table->decimal('current_usage', 15, 2)->default(0);
            $table->decimal('limit_value', 15, 2);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('usage_logs');
    }
};
