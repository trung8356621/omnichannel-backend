<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Models;

use App\Addons\SeoContentAi\Support\SeoAccessControl;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Keyword extends Model
{
    public const TYPE_NORMAL = 'normal';

    public const TYPE_SUGGEST = 'suggest';

    public const TYPE_FREE = 'free';

    /**
     * @return list<string>
     */
    public static function allowedTypes(): array
    {
        return [
            self::TYPE_NORMAL,
            self::TYPE_SUGGEST,
            self::TYPE_FREE,
        ];
    }

    public static function isNormalType(?string $type): bool
    {
        return in_array((string) $type, [self::TYPE_NORMAL, 'focus', 'internal'], true);
    }

    public const METRIC_RESCRAPE_KEEP = 'rescrape_keep';

    protected $connection = 'omi_seo_ai';

    protected $fillable = [
        'phrase',
        'type',
        'parent_id',
    ];

    protected $casts = [
        'parent_id' => 'integer',
    ];

    public static function decodePhrase(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = str_replace("\xC2\xA0", ' ', $value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    protected function phrase(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value): string => self::decodePhrase($value),
            set: fn (?string $value): string => self::decodePhrase($value),
        );
    }

    public function getNameAttribute(): string
    {
        return (string) $this->phrase;
    }

    public function getKeywordAttribute(): string
    {
        return (string) $this->phrase;
    }

    public function getVolumeAttribute(): ?int
    {
        $value = $this->resolvePivotForSite()?->search_volume;

        return $value !== null ? (int) $value : null;
    }

    public function getSearchVolumeAttribute(): ?int
    {
        return $this->volume;
    }

    public function getSiteIdAttribute(): ?int
    {
        return $this->resolveSiteId();
    }

    public function getTargetUrlAttribute(): ?string
    {
        return $this->targetUrlForSite((int) (SeoAccessControl::globalSiteId() ?? 0));
    }

    public function getMetricsAttribute(): ?array
    {
        $metrics = $this->resolvePivotForSite()?->metrics;

        return is_array($metrics) ? $metrics : null;
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function links(): BelongsToMany
    {
        return $this->belongsToMany(SeoLink::class, 'keyword_link', 'keyword_id', 'link_id')
            ->using(KeywordLink::class)
            ->withPivot(['search_volume', 'difficulty', 'metrics'])
            ->withTimestamps();
    }

    public function linksForSite(int $siteId): BelongsToMany
    {
        return $this->links()->where('seo_links.site_id', $siteId);
    }

    public function articles(): BelongsToMany
    {
        return $this->belongsToMany(SeoArticle::class, 'article_keyword', 'keyword_id', 'article_id')
            ->withPivot('weight', 'is_main')
            ->withTimestamps();
    }

    public function mainArticles(): BelongsToMany
    {
        return $this->belongsToMany(SeoArticle::class, 'article_keyword', 'keyword_id', 'article_id')
            ->wherePivot('is_main', true);
    }

    public function inboundLinks(): BelongsToMany
    {
        return $this->links();
    }

    public function articleKeywords(): HasMany
    {
        return $this->hasMany(ArticleKeyword::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'keyword_tag', 'keyword_id', 'tag_id');
    }

    public function resolveSiteId(?int $preferredSiteId = null): ?int
    {
        if ($preferredSiteId !== null && $preferredSiteId > 0) {
            return $preferredSiteId;
        }

        $globalSiteId = SeoAccessControl::globalSiteId();
        if ($globalSiteId !== null && $globalSiteId > 0) {
            return $globalSiteId;
        }

        if ($this->relationLoaded('links') && $this->links->isNotEmpty()) {
            return (int) $this->links->first()->site_id;
        }

        $siteId = $this->links()->orderBy('seo_links.id')->value('site_id');

        return is_numeric($siteId) ? (int) $siteId : null;
    }

    public function resolvePivotForSite(?int $siteId = null): ?KeywordLink
    {
        $siteId ??= $this->resolveSiteId();
        if ($siteId === null || $siteId <= 0) {
            return null;
        }

        $link = $this->resolvePrimaryLink($siteId);
        if ($link === null) {
            return null;
        }

        if ($link->relationLoaded('pivot') && $link->pivot instanceof KeywordLink) {
            return $link->pivot;
        }

        $loaded = $this->links()
            ->where('seo_links.id', $link->id)
            ->first();

        return $loaded?->pivot instanceof KeywordLink ? $loaded->pivot : null;
    }

    public function resolvePrimaryLink(?int $siteId = null): ?SeoLink
    {
        $siteId ??= $this->resolveSiteId();

        if ($this->relationLoaded('links')) {
            $links = $this->links;
            if ($siteId !== null && $siteId > 0) {
                $scoped = $links->filter(static fn (SeoLink $link): bool => (int) $link->site_id === $siteId);

                if ($scoped->isNotEmpty()) {
                    return $this->pickPrimaryLinkFromCollection($scoped);
                }
            }

            return $this->pickPrimaryLinkFromCollection($links);
        }

        $query = $this->links()->orderBy('seo_links.id');
        if ($siteId !== null && $siteId > 0) {
            $destination = (clone $query)
                ->where('seo_links.site_id', $siteId)
                ->whereNull('seo_links.source_article_id')
                ->first();

            if ($destination instanceof SeoLink) {
                return $destination;
            }

            $scoped = (clone $query)->where('seo_links.site_id', $siteId)->first();
            if ($scoped instanceof SeoLink) {
                return $scoped;
            }
        }

        return $query->first();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, SeoLink>  $links
     */
    private function pickPrimaryLinkFromCollection(\Illuminate\Support\Collection $links): ?SeoLink
    {
        if ($links->isEmpty()) {
            return null;
        }

        $destination = $links
            ->filter(static fn (SeoLink $link): bool => $link->source_article_id === null)
            ->sortBy('id')
            ->first();

        if ($destination instanceof SeoLink) {
            return $destination;
        }

        return $links->sortBy('id')->first();
    }

    public function targetUrlForSite(int $siteId): ?string
    {
        if ($siteId <= 0) {
            return null;
        }

        $url = trim((string) ($this->resolvePrimaryLink($siteId)?->url ?? ''));

        return $url !== '' ? $url : null;
    }

    public function hasSiteContext(int $siteId): bool
    {
        if ($siteId <= 0) {
            return false;
        }

        if ($this->relationLoaded('links')) {
            if ($this->links->contains(static fn (SeoLink $link): bool => (int) $link->site_id === $siteId)) {
                return true;
            }
        } elseif ($this->links()->where('seo_links.site_id', $siteId)->exists()) {
            return true;
        }

        return $this->articles()->where('articles.site_id', $siteId)->exists()
            || $this->mainArticles()->where('articles.site_id', $siteId)->exists();
    }

    /**
     * @param  Builder<Keyword>  $query
     * @return Builder<Keyword>
     */
    public function scopeForSite(Builder $query, int $siteId): Builder
    {
        if ($siteId <= 0) {
            return $query;
        }

        return $query->where(function (Builder $scopeQuery) use ($siteId): void {
            $scopeQuery
                ->whereHas(
                    'links',
                    static fn (Builder $linkQuery): Builder => $linkQuery->where('seo_links.site_id', $siteId),
                )
                ->orWhereHas(
                    'articles',
                    static fn (Builder $articleQuery): Builder => $articleQuery->where('articles.site_id', $siteId),
                );
        });
    }

    /**
     * @param  Builder<Keyword>  $query
     * @param  list<int>  $siteIds
     * @return Builder<Keyword>
     */
    public function scopeForSites(Builder $query, array $siteIds): Builder
    {
        $siteIds = array_values(array_filter(
            $siteIds,
            static fn (mixed $siteId): bool => is_numeric($siteId) && (int) $siteId > 0,
        ));

        if ($siteIds === []) {
            return $query->whereRaw('0 = 1');
        }

        return $query->where(function (Builder $scopeQuery) use ($siteIds): void {
            $scopeQuery
                ->whereHas(
                    'links',
                    static fn (Builder $linkQuery): Builder => $linkQuery->whereIn('seo_links.site_id', $siteIds),
                )
                ->orWhereHas(
                    'articles',
                    static fn (Builder $articleQuery): Builder => $articleQuery->whereIn('articles.site_id', $siteIds),
                );
        });
    }

    public function keepOnRescrapeForSite(int $siteId): bool
    {
        if (! in_array($this->type, [self::TYPE_FREE, self::TYPE_SUGGEST], true)) {
            return false;
        }

        $metrics = $this->resolvePivotForSite($siteId)?->metrics;

        return is_array($metrics) && ($metrics[self::METRIC_RESCRAPE_KEEP] ?? false) === true;
    }
}
