<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Automation\BusinessHook\Models;

use App\Support\Automation\AutomationModel;

/**
 * @property int $id
 * @property string $name
 * @property \Illuminate\Support\Carbon $last_beat_at
 * @property array<string, mixed>|null $meta
 */
final class AutomationSchedulerHeartbeat extends AutomationModel
{
    protected $table = 'automation_scheduler_heartbeats';

    protected $guarded = [];

    protected $casts = [
        'last_beat_at' => 'datetime',
        'meta' => 'array',
    ];
}
