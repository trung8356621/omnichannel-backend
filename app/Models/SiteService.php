<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteService extends Model
{
    use HasFactory;

    /**
     * Tên bảng trong database.
     */
    protected $table = 'site_services';

    /**
     * Các trường cho phép gán dữ liệu hàng loạt.
     */
    protected $fillable = [
        'site_id',
        'service_id',
        'status',
        'settings',
    ];

    /**
     * Ép kiểu dữ liệu cho các trường.
     * Quan trọng: 'settings' phải là array để Filament KeyValue làm việc được với JSON.
     */
    protected $casts = [
        'settings' => 'array',
        'status' => 'string',
    ];

    /**
     * Liên kết với model Site.
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * Liên kết với model Service (Addon).
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * Tìm kiếm file Settings.php trong thư mục Addon và lấy giá trị mặc định
     */
    protected static function resolveAddonDefaults($serviceId): array
    {
        $service = Service::find($serviceId);
        if (!$service)
            return [];

        // Lấy namespace của Provider (ví dụ: App\Addons\SeoContentAi\SeoContentAiServiceProvider)
        $providerNamespace = $service->addon_namespace;

        // Chuyển đổi sang namespace của file Settings (ví dụ: App\Addons\SeoContentAi\Settings)
        $settingsClass = str_replace(
            class_basename($providerNamespace),
            'Settings',
            $providerNamespace
        );

        // Kiểm tra xem class Settings có tồn tại và có method getDefaults không
        if (class_exists($settingsClass) && method_exists($settingsClass, 'getDefaults')) {
            return (new $settingsClass())->getDefaults();
        }

        return [];
    }
}
