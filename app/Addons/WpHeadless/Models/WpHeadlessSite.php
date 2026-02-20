<?php

declare(strict_types=1);

namespace App\Addons\WpHeadless\Models;

use App\Models\Site;
use Illuminate\Database\Eloquent\Model;

class WpHeadlessSite extends Model
{
    protected $connection = 'wp_headless';

    protected $table = 'wp_headless_sites';

    public $incrementing = false;

    protected $keyType = 'int';

    protected $fillable = ['id', 'type', 'public_url', 'headless_next_dev', 'is_dev', 'settings'];

    protected $casts = [
        'is_dev'   => 'boolean',
        'settings' => 'array',
    ];

    /**
     * Site chính (bảng sites trong DB mặc định), cùng id.
     * Query trên connection mặc định vì bảng sites không nằm trong DB wp_headless.
     */
    public function getMainSite(): ?Site
    {
        return Site::on(config('database.default'))->find($this->id);
    }

    /**
     * URL gốc Next.js để proxy / gửi webhook.
     * is_dev = true → domain chính từ bảng sites (url).
     * is_dev = false → headless_next_dev.
     */
    public function getNextjsBaseUrl(): string
    {
        if (!$this->is_dev) {
            $site = $this->getMainSite();
            if ($site && !empty($site->url)) {
                return rtrim(trim((string) $site->url), '/');
            }
            if ($site && !empty($site->domain)) {
                $d = trim((string) $site->domain);
                return preg_match('#^https?://#i', $d) ? $d : 'https://' . $d;
            }
        }
        $url = $this->headless_next_dev ?? '';
        $url = rtrim(trim($url), '/');
        if ($url !== '' && !preg_match('#^https?://#i', $url)) {
            $url = 'http://' . $url;
        }
        return $url;
    }

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
