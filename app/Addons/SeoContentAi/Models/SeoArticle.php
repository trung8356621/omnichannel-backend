<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Models;

use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Bản ghi nội dung SEO trên DB addon (bảng `articles`, connection `omi_seo_ai`).
 */
class SeoArticle extends Model
{
    use SoftDeletes;

    protected $connection = 'omi_seo_ai';

    /** @var string Bảng vật lý là `articles` (SEO / bài viết đồng bộ). */
    protected $table = 'articles';

    protected $guarded = [];

    protected $casts = [
        'blocks'       => 'array',
        'seo_score'    => 'decimal:2',
        'published_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function site(): BelongsTo
    {
        $relation = $this->belongsTo(Site::class);
        // Site nằm DB chính; không dùng connection addon `omi_seo_ai`.
        $relation->getRelated()->setConnection((string) config('database.default'));

        return $relation;
    }

    public function promptResult(): BelongsTo
    {
        return $this->belongsTo(PromptResult::class);
    }

    public function keywords(): BelongsToMany
    {
        return $this->belongsToMany(Keyword::class, 'article_keyword', 'article_id', 'keyword_id')
            ->withPivot('weight')
            ->withTimestamps();
    }

    public function articleMetas(): HasMany
    {
        return $this->hasMany(ArticleMeta::class, 'article_id');
    }

    public function articleKeywords(): HasMany
    {
        return $this->hasMany(ArticleKeyword::class, 'article_id');
    }
}
