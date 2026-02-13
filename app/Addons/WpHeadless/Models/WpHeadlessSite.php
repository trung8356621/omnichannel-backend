<?php

declare(strict_types=1);

namespace App\Addons\WpHeadless\Models;

use Illuminate\Database\Eloquent\Model;

class WpHeadlessSite extends Model
{
    protected $connection = 'wp_headless';

    protected $table = 'wp_headless_sites';

    public $incrementing = false;

    protected $keyType = 'int';

    protected $fillable = ['id', 'type', 'public_url', 'settings'];

    protected $casts = [
        'settings' => 'array',
    ];

    /**
     * Slug dùng cho route /site/{slug}: host từ public_url, dấu chấm đổi thành gạch ngang.
     * VD: https://myblog.com -> myblog-com
     */
    public function getPublicUrlSlugAttribute(): string
    {
        $host = $this->public_url ? parse_url($this->public_url, PHP_URL_HOST) : null;
        if ($host === null || $host === '') {
            return 'site-' . $this->id;
        }
        return str_replace('.', '-', $host);
    }

    public function styles()
    {
        return $this->hasMany(WpHeadlessStyle::class, 'site_id', 'id');
    }

    public function templates()
    {
        return $this->hasMany(WpHeadlessTemplate::class, 'site_id', 'id');
    }
}
