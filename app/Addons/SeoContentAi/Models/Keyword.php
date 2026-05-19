<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Models;

use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Keyword extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $guarded = [];

    protected $casts = [
        'metrics'    => 'array',
        'difficulty' => 'decimal:2',
    ];

    /**
     * Alias cho Filament / API (cột DB là `phrase`).
     */
    public function getKeywordAttribute(): string
    {
        return (string) $this->phrase;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function articles(): BelongsToMany
    {
        return $this->belongsToMany(SeoArticle::class, 'article_keyword', 'keyword_id', 'article_id')
            ->withPivot('weight')
            ->withTimestamps();
    }

    public function articleKeywords(): HasMany
    {
        return $this->hasMany(ArticleKeyword::class);
    }
}
