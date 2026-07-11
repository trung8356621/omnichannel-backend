<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class KeywordRankSnapshot extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'keyword_rank_snapshots';

    protected $guarded = [];

    protected $casts = [
        'position' => 'float',
        'search_volume' => 'integer',
        'allintitle' => 'integer',
        'checked_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function keyword(): BelongsTo
    {
        return $this->belongsTo(Keyword::class, 'keyword_id');
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(KeywordRankCheckRun::class, 'run_id');
    }
}
