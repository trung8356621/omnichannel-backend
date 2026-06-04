<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Models;

use App\Addons\SeoContentAi\Models\Concerns\BelongsToOnDefaultConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoProjectRun extends Model
{
    use BelongsToOnDefaultConnection;

    public const MODE_FULL = 'full';

    public const MODE_TEST = 'test';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_project_runs';

    protected $guarded = [];

    protected $casts = [
        'project_id' => 'integer',
        'user_id' => 'integer',
        'total' => 'integer',
        'succeeded' => 'integer',
        'failed' => 'integer',
        'items' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(SeoProject::class, 'project_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsToOnDefaultConnection(User::class, 'user_id');
    }

    public function isTestMode(): bool
    {
        return $this->mode === self::MODE_TEST;
    }
}
