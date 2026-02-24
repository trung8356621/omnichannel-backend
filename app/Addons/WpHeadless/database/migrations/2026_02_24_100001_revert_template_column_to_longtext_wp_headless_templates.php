<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Đổi cột template từ JSON về LONGTEXT để tránh lỗi CONSTRAINT khi insert (MySQL/MariaDB
 * có thể từ chối document JSON lớn hoặc phức tạp). Ứng dụng vẫn validate qua
 * WpHeadlessTemplate::normalizeTemplateValue() trước khi ghi.
 */
return new class extends Migration
{
    protected $connection = 'wp_headless';

    public function up(): void
    {
        $conn = Schema::connection($this->connection)->getConnection();
        $table = $conn->getTablePrefix() . 'wp_headless_templates';

        DB::connection($this->connection)->statement(
            "ALTER TABLE {$table} MODIFY template LONGTEXT NULL COMMENT 'Template JSON (children + classes) hoặc chuỗi JSON sidebar/widget'"
        );
    }

    public function down(): void
    {
        $conn = Schema::connection($this->connection)->getConnection();
        $table = $conn->getTablePrefix() . 'wp_headless_templates';

        DB::connection($this->connection)->statement(
            "ALTER TABLE {$table} MODIFY template JSON NULL COMMENT 'Template JSON (children + classes) hoặc chuỗi JSON sidebar/widget'"
        );
    }
};
