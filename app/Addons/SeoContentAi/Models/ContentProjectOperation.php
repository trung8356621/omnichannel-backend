<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Models;

use Illuminate\Database\Eloquent\Model;

class ContentProjectOperation extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_content_project_operations';

    protected $guarded = [];

    protected $casts = [
        'metadata' => 'array',
        'success' => 'boolean',
        'duration_ms' => 'integer',
        'actor_id' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];
}
