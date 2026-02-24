<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migration mới: đổi cột template (wp_headless_templates) từ LONGTEXT sang JSON.
 * Trước khi ALTER: set NULL cho mọi giá trị không phải JSON hợp lệ để tránh lỗi constraint.
 */
return new class extends Migration
{
    protected $connection = 'wp_headless';

    public function up(): void
    {
        $this->nullifyInvalidJsonInTemplateColumn();

        $conn = Schema::connection($this->connection)->getConnection();
        $table = $conn->getTablePrefix() . 'wp_headless_templates';

        DB::connection($this->connection)->statement(
            "ALTER TABLE {$table} MODIFY template JSON NULL COMMENT 'Template JSON (children + classes) hoặc chuỗi JSON sidebar/widget'"
        );
    }

    public function down(): void
    {
        $conn = Schema::connection($this->connection)->getConnection();
        $table = $conn->getTablePrefix() . 'wp_headless_templates';

        DB::connection($this->connection)->statement(
            "ALTER TABLE {$table} MODIFY template LONGTEXT NULL COMMENT 'HTML template'"
        );
    }

    /**
     * Set template = NULL cho mọi row có giá trị không phải JSON hợp lệ.
     */
    private function nullifyInvalidJsonInTemplateColumn(): void
    {
        $connection = DB::connection($this->connection);
        $rows = $connection->table('wp_headless_templates')
            ->whereNotNull('template')
            ->get(['id', 'template']);

        foreach ($rows as $row) {
            $value = $row->template;
            if ($value === null || $value === '') {
                continue;
            }
            $s = is_string($value) ? trim($value) : (string) $value;
            if ($s === '') {
                $connection->table('wp_headless_templates')->where('id', $row->id)->update(['template' => null]);
                continue;
            }
            $s = preg_replace('/^\xEF\xBB\xBF/', '', $s);
            json_decode($s);
            if (json_last_error() !== \JSON_ERROR_NONE) {
                $connection->table('wp_headless_templates')->where('id', $row->id)->update(['template' => null]);
            }
        }
    }
};
