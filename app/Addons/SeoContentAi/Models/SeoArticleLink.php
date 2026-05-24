<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoArticleLink extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_article_links';

    protected $guarded = [];

    protected $casts = [
        'is_nofollow' => 'boolean',
    ];

    public function article(): BelongsTo
    {
        return $this->belongsTo(SeoArticle::class, 'article_id');
    }

    public function keyword(): BelongsTo
    {
        return $this->belongsTo(Keyword::class, 'keyword_id');
    }
}
