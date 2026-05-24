<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Models;

use App\Addons\SeoContentAi\Models\Concerns\BelongsToOnDefaultConnection;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Keyword extends Model
{
    use BelongsToOnDefaultConnection;

    public const TYPE_FOCUS = 'focus';

    public const TYPE_INTERNAL = 'internal';

    protected $connection = 'omi_seo_ai';

    protected $guarded = [];

    protected $casts = [
        'metrics'    => 'array',
        'difficulty' => 'decimal:2',
    ];

    /**
     * Alias cho Filament / API (cột DB là `phrase`).
     */
    public function getNameAttribute(): string
    {
        return (string) $this->phrase;
    }

    /**
     * Alias volume ↔ search_volume.
     */
    public function getVolumeAttribute(): ?int
    {
        $value = $this->search_volume;

        return $value !== null ? (int) $value : null;
    }

    /**
     * Alias cho Filament / API (cột DB là `phrase`).
     */
    public function getKeywordAttribute(): string
    {
        return (string) $this->phrase;
    }

    public function user(): BelongsTo
    {
        return $this->belongsToOnDefaultConnection(User::class, 'user_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsToOnDefaultConnection(Site::class, 'site_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function articles(): BelongsToMany
    {
        return $this->belongsToMany(SeoArticle::class, 'article_keyword', 'keyword_id', 'article_id')
            ->withPivot('weight', 'is_main')
            ->withTimestamps();
    }

    /**
     * Các bài viết nhận từ khóa này làm từ khóa CHÍNH.
     */
    public function mainArticles(): BelongsToMany
    {
        return $this->belongsToMany(SeoArticle::class, 'article_keyword', 'keyword_id', 'article_id')
            ->wherePivot('is_main', true);
    }

    /**
     * Bài viết có internal link gắn keyword_id (anchor text).
     */
    public function articlesViaInternalLink(): BelongsToMany
    {
        return $this->belongsToMany(
            SeoArticle::class,
            'seo_article_links',
            'keyword_id',
            'article_id',
        )->where('seo_article_links.type', 'internal');
    }

    /**
     * Internal link dùng anchor text trùng từ khóa này.
     */
    public function inboundLinks(): HasMany
    {
        return $this->hasMany(SeoArticleLink::class, 'keyword_id');
    }

    public function articleKeywords(): HasMany
    {
        return $this->hasMany(ArticleKeyword::class);
    }
}
