<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Đổi cột template từ LONGTEXT (HTML) sang JSON.
 * Mọi template từ WordPress/Laravel sync giờ là JSON (children + classes). Header/footer cũng lưu JSON.
 * Dữ liệu cũ: giá trị không phải JSON (HTML hoặc số ID) → set NULL; lần sync sau sẽ ghi lại JSON.
 */
return new class extends Migration
{
    protected $connection = 'wp_headless';

    public function up(): void
    {
        $conn = Schema::connection($this->connection)->getConnection();
        $table = $conn->getTablePrefix() . 'wp_headless_templates';

        // Giá trị không phải JSON (HTML, số ID, chuỗi rỗng) → NULL để cột chuyển sang JSON được.
        DB::connection($this->connection)->statement(
            "UPDATE {$table} SET template = NULL WHERE template IS NOT NULL AND (TRIM(template) = '' OR TRIM(template) LIKE '<%' OR TRIM(template) REGEXP '^[0-9]+$')"
        );

        DB::connection($this->connection)->statement("ALTER TABLE {$table} MODIFY template JSON NULL COMMENT 'Template JSON (children + classes) hoặc chuỗi JSON sidebar/widget'");
    }

    public function down(): void
    {
        $conn = Schema::connection($this->connection)->getConnection();
        $table = $conn->getTablePrefix() . 'wp_headless_templates';
        DB::connection($this->connection)->statement("ALTER TABLE {$table} MODIFY template LONGTEXT NULL COMMENT 'HTML template'");
    }
};
