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
     * is_dev = true → headless_next_dev.
     * is_dev = false → headless_next_dev hoặc build từ site domain (https nếu ssl).
     */
    public function getNextjsBaseUrl(): string
    {
        $url = $this->resolveNextjsBaseUrlFromSite();
        $url = rtrim(trim($url), '/');
        if ($url !== '' && !preg_match('#^https?://#i', $url)) {
            $url = ($this->is_dev ? 'http' : 'https') . '://' . $url;
        }
        return $url;
    }

    /**
     * URL dùng cho webhook (receive, updated). Ưu tiên config nextjs_webhook_url (tránh lỗi kết nối localhost).
     */
    public function getNextjsWebhookUrl(): string
    {
        $override = config('wp-headless.nextjs_webhook_url');
        if ($override !== null && trim((string) $override) !== '') {
            return rtrim(trim((string) $override), '/');
        }
        return $this->getNextjsBaseUrl();
    }

    private function resolveNextjsBaseUrlFromSite(): string
    {
        if (!$this->is_dev) {
            $site = $this->getMainSite();
            if ($site && !empty($site->domain)) {
                $d = trim((string) $site->domain);
                $scheme = !empty($site->ssl) ? 'https' : 'http';
                $base = preg_match('#^https?://#i', $d) ? $d : $scheme . '://' . $d;
                return rtrim($base, '/');
            }
            $url = $this->headless_next_dev ?? '';
            $url = rtrim(trim($url), '/');
            if ($url !== '' && !preg_match('#^https?://#i', $url)) {
                $url = 'https://' . $url;
            }
            return $url;
        }
        return (string) ($this->headless_next_dev ?? '');
    }

    /**
     * URL gốc WordPress (uploads, assets). Dùng scheme + domain từ Site (http nếu !ssl, https nếu ssl).
     */
    public function getWpUploadsOrigin(): string
    {
        $site = $this->getMainSite();
        if (!$site || empty($site->domain)) {
            return '';
        }
        $d = trim((string) $site->domain);
        $scheme = !empty($site->ssl) ? 'https' : 'http';
        $base = preg_match('#^https?://#i', $d) ? $d : $scheme . '://' . $d;
        return rtrim($base, '/');
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
