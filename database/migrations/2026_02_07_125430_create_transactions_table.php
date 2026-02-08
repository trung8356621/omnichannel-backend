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
        // Bảng TRANSACTIONS: Nhật ký biến động số dư
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['deposit', 'withdraw', 'payment', 'refund']);
            $table->decimal('amount', 15, 2);
            $table->decimal('before_balance', 15, 2);
            $table->decimal('after_balance', 15, 2);
            $table->enum('status', ['pending', 'completed', 'failed', 'canceled'])->default('pending');
            $table->string('reference_id')->nullable()->index(); // Mã tham chiếu từ cổng thanh toán
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
