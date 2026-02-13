<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('frontend_projects', function (Blueprint $table) {
            $table->id();
            $table->string('name')->comment('Tên hiển thị project');
            $table->string('type', 32)->default('nextjs')->comment('nextjs, react');
            $table->string('package_json_path', 1024)->comment('Đường dẫn thư mục chứa package.json (tương đối từ base_path hoặc tuyệt đối)');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('frontend_projects');
    }
};
