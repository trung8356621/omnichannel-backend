<?php

declare(strict_types=1);

namespace App\Addons\WpHeadless\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kiểm tra connection wp_headless và (tùy chọn) đổi cột template sang JSON.
 * Migration chạy trên connection wp_headless = database trong addon.json (vd: omi_wp_headless).
 * Nếu bạn đang xem database khác trong MySQL client thì sẽ thấy cột vẫn LONGTEXT.
 */
final class TemplateColumnToJsonCommand extends Command
{
    protected $signature = 'wp-headless:template-to-json
                            {--run : Thực hiện ALTER (set NULL giá trị không phải JSON rồi đổi cột sang JSON)}';

    protected $description = 'Kiểm tra DB wp_headless + kiểu cột template; tùy chọn --run để đổi sang JSON';

    public function handle(): int
    {
        $connection = 'wp_headless';
        $conn = Schema::connection($connection)->getConnection();
        $dbName = $conn->getDatabaseName();
        $prefix = $conn->getTablePrefix();
        $table = $prefix . 'wp_headless_templates';

        $this->info("Connection <comment>{$connection}</comment> dùng database: <comment>{$dbName}</comment>");
        $currentDb = DB::connection($connection)->selectOne('SELECT DATABASE() as db');
        $actualDb = $currentDb->db ?? $dbName;
        if ($actualDb !== $dbName) {
            $this->warn("Cảnh báo: DATABASE() trả về <comment>{$actualDb}</comment>, config có <comment>{$dbName}</comment>.");
        }
        $this->line('Khi xem trong MySQL/phpMyAdmin, hãy chọn đúng database: <comment>' . $actualDb . '</comment>');
        $this->newLine();

        $fullTable = "`{$actualDb}`.`{$table}`";
        try {
            $columns = DB::connection($connection)->select("SHOW COLUMNS FROM {$fullTable} WHERE Field = 'template'");
        } catch (\Throwable $e) {
            $this->error('Không đọc được cột: ' . $e->getMessage());
            return self::FAILURE;
        }

        if (empty($columns)) {
            $this->warn("Bảng {$table} không có cột 'template'.");
            return self::FAILURE;
        }

        $type = $columns[0]->Type ?? 'unknown';
        $this->info("Kiểu cột <comment>template</comment> hiện tại: <comment>{$type}</comment>");

        if (stripos($type, 'json') !== false) {
            $this->info('Cột đã là JSON.');
            return self::SUCCESS;
        }

        if (!$this->option('run')) {
            $this->newLine();
            $this->line('Để đổi cột sang JSON trên đúng connection, chạy:');
            $this->line('  <comment>php artisan wp-headless:template-to-json --run</comment>');
            return self::SUCCESS;
        }

        $this->newLine();
        if (!$this->confirm('Thực hiện: set NULL các giá trị không phải JSON, rồi ALTER cột sang JSON?')) {
            return self::SUCCESS;
        }

        $this->nullifyInvalidJson($connection);
        $alterSql = "ALTER TABLE {$fullTable} MODIFY template JSON NULL COMMENT 'Template JSON (children + classes) hoặc chuỗi JSON sidebar/widget'";
        $this->line('SQL: ' . $alterSql);
        DB::connection($connection)->statement($alterSql);
        $this->info('Đã đổi cột template sang JSON.');

        $columns = DB::connection($connection)->select("SHOW COLUMNS FROM {$fullTable} WHERE Field = 'template'");
        $type = $columns[0]->Type ?? 'unknown';
        $this->info("Kiểu cột sau khi đổi: <comment>{$type}</comment>");

        return self::SUCCESS;
    }

    private function nullifyInvalidJson(string $connection): void
    {
        $rows = DB::connection($connection)->table('wp_headless_templates')
            ->whereNotNull('template')
            ->get(['id', 'template']);

        $updated = 0;
        foreach ($rows as $row) {
            $value = $row->template;
            if ($value === null || $value === '') {
                continue;
            }
            $s = is_string($value) ? trim($value) : (string) $value;
            if ($s === '') {
                DB::connection($connection)->table('wp_headless_templates')->where('id', $row->id)->update(['template' => null]);
                $updated++;
                continue;
            }
            $s = preg_replace('/^\xEF\xBB\xBF/', '', $s);
            json_decode($s);
            if (json_last_error() !== \JSON_ERROR_NONE) {
                DB::connection($connection)->table('wp_headless_templates')->where('id', $row->id)->update(['template' => null]);
                $updated++;
            }
        }
        if ($updated > 0) {
            $this->line("Đã set NULL {$updated} row có giá trị không phải JSON.");
        }
    }
}
