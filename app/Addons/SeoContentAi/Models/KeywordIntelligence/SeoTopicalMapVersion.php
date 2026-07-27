<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Models\KeywordIntelligence;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoTopicalMapVersion extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_topical_map_versions';

    protected $guarded = [];

    protected $casts = [
        'workspace_id' => 'integer',
        'version' => 'integer',
        'snapshot' => 'array',
        'summary' => 'array',
        'generated_by' => 'integer',
        'generated_at' => 'datetime',
    ];

    /** @return BelongsTo<SeoKeywordWorkspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(SeoKeywordWorkspace::class, 'workspace_id');
    }
}
