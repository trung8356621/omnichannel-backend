<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        DB::connection($this->connection)->statement(
            "ALTER TABLE seo_project_tasks MODIFY COLUMN type ENUM('rewrite', 'new_keyword', 'new_title', 'improve') NOT NULL COMMENT 'rewrite: viết lại bài lỗi, new_keyword: từ khóa mới, new_title: viết mới theo tiêu đề, improve: tối ưu thủ công'",
        );
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement(
            "UPDATE seo_project_tasks SET type = 'new_keyword' WHERE type = 'new_title'",
        );

        DB::connection($this->connection)->statement(
            "ALTER TABLE seo_project_tasks MODIFY COLUMN type ENUM('rewrite', 'new_keyword', 'improve') NOT NULL COMMENT 'rewrite: viết lại bài lỗi, new_keyword: từ khóa mới, improve: tối ưu thủ công'",
        );
    }
};
