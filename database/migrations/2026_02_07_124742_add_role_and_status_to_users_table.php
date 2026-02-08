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
        Schema::table('users', function (Blueprint $table) {
            // Cấp bậc tài khoản
            $table->enum('role', ['admin', 'owner', 'staff'])->default('owner')->after('password')->index();
            // Trạng thái hoạt động
            $table->enum('status', ['normal', 'block', 'pending'])->default('normal')->after('role')->index();
            // ID của chủ sở hữu (nếu là tài khoản staff)
            $table->unsignedBigInteger('parent_id')->nullable()->after('id')->index();
            $table->softDeletes(); // Thêm tính năng xóa mềm
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            //
        });
    }
};
