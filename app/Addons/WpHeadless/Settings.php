<?php
namespace App\Addons\SeoContentAi;

use Illuminate\Support\Str;

class Settings
{
    /**
     * Định nghĩa các trường dữ liệu mặc định cho Addon SEO Content AI.
     * * Hàm này được gọi tự động từ Model SiteService khi bản ghi mới được tạo.
     * Bạn có thể thêm bất kỳ logic nào ở đây (như random key, check config hệ thống, v.v.)
     * * @return array
     */
    public function getDefaults(): array
    {
        return [
            'MIGRATION_TOKEN' => Str::random(32),
            'READ_TOKEN' => Str::random(32),
        ];
    }
}
