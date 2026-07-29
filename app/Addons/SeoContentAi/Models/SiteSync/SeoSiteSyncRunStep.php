<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Models\SiteSync;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoSiteSyncRunStep extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_site_sync_run_steps';

    protected $fillable = [
        'run_id',
        'step_key',
        'step_order',
        'status',
        'metrics',
        'error_message',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'metrics' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(SeoSiteSyncRun::class, 'run_id');
    }
}
