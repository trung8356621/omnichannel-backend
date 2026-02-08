<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

//Dùng để theo dõi tiến độ các công việc nặng như Build, Sync dữ liệu, hoặc Crawl.
return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('task_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('task_type')->index(); // ví dụ: build_headless, sync_posts, generate_sitemap
            $table->enum('status', ['pending', 'running', 'completed', 'failed'])->default('pending');
            $table->integer('progress_percent')->default(0); // Từ 0 - 100
            $table->longText('error_log')->nullable(); // Lưu vết nếu task bị lỗi
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('task_jobs');
    }
};
