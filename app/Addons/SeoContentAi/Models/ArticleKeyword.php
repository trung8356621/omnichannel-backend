<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArticleKeyword extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'article_keyword';

    protected $guarded = [];

    protected $casts = [
        'weight' => 'decimal:4',
    ];

    public function article(): BelongsTo
    {
        return $this->belongsTo(SeoArticle::class, 'article_id');
    }

    public function keyword(): BelongsTo
    {
        return $this->belongsTo(Keyword::class);
    }
}
