<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Models;

use App\Addons\SeoContentAi\Models\Concerns\BelongsToOnDefaultConnection;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SeoProject extends Model
{
    use BelongsToOnDefaultConnection;

    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_PAUSED = 'paused';

    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_projects';

    protected $guarded = [];

    protected $casts = [
        'month' => 'date',
        'total_tasks' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsToOnDefaultConnection(User::class, 'user_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(SeoProjectTask::class, 'project_id');
    }

    public function monthCarbon(): Carbon
    {
        return Carbon::parse($this->month)->startOfMonth();
    }

    public function maxTasksAllowed(): int
    {
        return $this->monthCarbon()->daysInMonth;
    }

    public static function defaultNameFromMonth(Carbon|string $month): string
    {
        $carbon = Carbon::parse($month)->startOfMonth();

        return 'project ' . $carbon->format('n/Y');
    }

    /**
     * @return array<string, string>
     */
    public static function statusOptions(): array
    {
        return [
            self::STATUS_PENDING => 'Chờ duyệt',
            self::STATUS_RUNNING => 'Đang chạy',
            self::STATUS_COMPLETED => 'Hoàn thành',
            self::STATUS_PAUSED => 'Tạm dừng',
        ];
    }
}
