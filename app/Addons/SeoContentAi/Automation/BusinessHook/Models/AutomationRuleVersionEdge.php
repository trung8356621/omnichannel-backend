<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Automation\BusinessHook\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AutomationRuleVersionEdge extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'automation_rule_version_edges';

    protected $guarded = [];

    protected $casts = [
        'automation_rule_version_id' => 'integer',
        'priority' => 'integer',
        'condition' => 'array',
    ];

    public function version(): BelongsTo
    {
        return $this->belongsTo(AutomationRuleVersion::class, 'automation_rule_version_id');
    }
}
