<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Models;

use App\Addons\SeoContentAi\Models\Concerns\BelongsToOnDefaultConnection;
use App\Models\Site;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SeoProject extends Model
{
    use BelongsToOnDefaultConnection;

    public const STATUS_PENDING = 'pending';

    public const STATUS_MANUAL = 'manual';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_APPROVED = 'approved';

    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_projects';

    protected $guarded = [];

    protected $casts = [
        'month' => 'date',
        'site_id' => 'integer',
        'total_tasks' => 'integer',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsToOnDefaultConnection(Site::class, 'site_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsToOnDefaultConnection(User::class, 'user_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(SeoProjectTask::class, 'project_id');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(SeoProjectRun::class, 'project_id');
    }

    public function monthCarbon(): Carbon
    {
        return Carbon::parse($this->month)->startOfMonth();
    }

    public function maxTasksAllowed(): int
    {
        return $this->monthCarbon()->daysInMonth;
    }

    public function isExecutionMonthOpen(): bool
    {
        return now()->lte($this->monthCarbon()->copy()->endOfMonth()->endOfDay());
    }

    public function registeredTaskCount(): int
    {
        if ($this->relationLoaded('tasks')) {
            return $this->tasks->count();
        }

        return (int) $this->tasks()->count();
    }

    public function remainingTaskCapacity(): int
    {
        return max(0, $this->maxTasksAllowed() - $this->registeredTaskCount());
    }

    public function canRegisterMoreTasks(): bool
    {
        return $this->isExecutionMonthOpen() && $this->remainingTaskCapacity() > 0;
    }

    public function syncTotalTasksCounter(): void
    {
        $count = (int) $this->tasks()->count();

        if ((int) ($this->total_tasks ?? 0) === $count) {
            return;
        }

        $this->update(['total_tasks' => $count]);
    }

    public static function defaultNameFromMonth(Carbon|string $month): string
    {
        $carbon = Carbon::parse($month)->startOfMonth();

        return 'project '.$carbon->format('n/Y');
    }

    /**
     * @return array<string, string>
     */
    public static function statusOptions(): array
    {
        return [
            self::STATUS_APPROVED => 'Đã duyệt',
            self::STATUS_PENDING => 'Chờ duyệt',
            self::STATUS_MANUAL => 'Thủ công',
            self::STATUS_RUNNING => 'Đang chạy',
            self::STATUS_COMPLETED => 'Hoàn thành',
            self::STATUS_PAUSED => 'Tạm dừng',
        ];
    }
}
