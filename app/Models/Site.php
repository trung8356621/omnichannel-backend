<?php

namespace App\Models;

use App\Models\Concerns\UsesCoreDatabaseConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Site extends Model
{
    use SoftDeletes;
    use UsesCoreDatabaseConnection;

    protected $fillable = ['user_id', 'subscription_id', 'domain', 'status', 'ssl'];

    protected $casts = ['ssl' => 'boolean'];

    protected static function booted(): void
    {
        static::deleting(function (Site $site): void {
            if ($site->isForceDeleting()) {
                return;
            }

            $domain = strtolower(trim((string) $site->getOriginal('domain')));

            if ($domain === '' || str_contains($domain, '__trashed__')) {
                return;
            }

            $site->forceFill([
                'domain' => $domain.'__trashed__'.$site->getKey(),
            ])->saveQuietly();
        });
    }

    public function siteServices()
    {
        return $this->hasMany(SiteService::class);
    }

    /**
     * SiteService ưu tiên mở trang cấu hình dịch vụ (wp-headless trước, sau đó bản ghi đầu tiên).
     */
    public function primarySiteServiceForSettings(): ?SiteService
    {
        if (! $this->relationLoaded('siteServices')) {
            $this->load(['siteServices.service']);
        }

        foreach ($this->siteServices as $siteService) {
            if (($siteService->service?->slug ?? null) === 'wp-headless') {
                return $siteService;
            }
        }

        return $this->siteServices->first();
    }

    /** Site có đang kích hoạt service wp-headless (status active) không. */
    public function hasActiveWpHeadless(): bool
    {
        return $this->siteServices()
            ->whereHas('service', fn ($q) => $q->where('slug', 'wp-headless'))
            ->where('status', 'active')
            ->exists();
    }

    public function metas()
    {
        return $this->hasMany(SiteMeta::class);
    }

    public function taskJobs()
    {
        return $this->hasMany(TaskJob::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // Helper để lấy nhanh một giá trị meta
    public function getMeta($key, $default = null)
    {
        $meta = $this->metas()->where('meta_key', $key)->first();

        return $meta ? $meta->meta_value : $default;
    }
}
