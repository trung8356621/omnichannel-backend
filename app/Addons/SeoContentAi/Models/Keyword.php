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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function site(): BelongsTo
    {
        $relation = $this->belongsTo(Site::class);
        $relation->getRelated()->setConnection((string) config('database.default'));

        return $relation;
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
