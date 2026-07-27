<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Models\KeywordIntelligence;

use App\Addons\SeoContentAi\Enums\KeywordIntelligence\KeywordAnalysisStage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoKeywordAnalysisOperation extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_keyword_analysis_operations';

    protected $guarded = [];

    protected $casts = [
        'workspace_id' => 'integer',
        'tenant_id' => 'integer',
        'site_id' => 'integer',
        'stage' => KeywordAnalysisStage::class,
        'progress' => 'integer',
        'summary' => 'array',
        'created_by' => 'integer',
    ];

    /** @return BelongsTo<SeoKeywordWorkspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(SeoKeywordWorkspace::class, 'workspace_id');
    }
}
