<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoProjectArchiveItem extends Model
{
    public const UPDATED_AT = null;

    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_project_archive_items';

    protected $guarded = [];

    protected $casts = [
        'seo_project_archive_id' => 'integer',
        'article_id' => 'integer',
        'created_at' => 'datetime',
    ];

    public function archive(): BelongsTo
    {
        return $this->belongsTo(SeoProjectArchive::class, 'seo_project_archive_id');
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(SeoArticle::class, 'article_id');
    }
}
