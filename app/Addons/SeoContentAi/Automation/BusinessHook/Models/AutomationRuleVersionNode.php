<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Automation\BusinessHook\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AutomationRuleVersionNode extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'automation_rule_version_nodes';

    protected $guarded = [];

    protected $casts = [
        'automation_rule_version_id' => 'integer',
        'position' => 'integer',
        'config' => 'array',
        'input_mapping' => 'array',
        'settings' => 'array',
        'ui_position' => 'array',
        'is_enabled' => 'boolean',
    ];

    public function version(): BelongsTo
    {
        return $this->belongsTo(AutomationRuleVersion::class, 'automation_rule_version_id');
    }
}
