<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Models\Site;
use Illuminate\Database\Eloquent\Builder;

final class SeoMainDomainService
{
    public function resolveMainSite(): ?Site
    {
        $query = Site::query()
            ->whereHas('metas', function (Builder $inner): void {
                $inner->where('meta_key', 'seo_is_main')
                    ->where('meta_value', '1');
            });

        if (auth()->user()?->role !== 'admin') {
            $query->where('user_id', auth()->id());
        }

        $site = $query->orderBy('id')->first();
        if ($site instanceof Site) {
            return $site;
        }

        $fallback = Site::query()->orderBy('id');
        if (auth()->user()?->role !== 'admin') {
            $fallback->where('user_id', auth()->id());
        }

        $site = $fallback->first();

        return $site instanceof Site ? $site : null;
    }

    public function resolveMainSiteId(): ?int
    {
        $site = $this->resolveMainSite();

        return $site !== null ? (int) $site->id : null;
    }

    public function resolveMainSiteLabel(): string
    {
        $site = $this->resolveMainSite();
        if ($site === null) {
            return 'Chưa có miền chính';
        }

        return trim((string) $site->domain) ?: ('Site #' . $site->id);
    }
}
