<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Models;

use App\Addons\SeoContentAi\Models\Concerns\BelongsToOnDefaultConnection;
use App\Models\Site;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SeoProjectTask extends Model
{
    use BelongsToOnDefaultConnection;

    /**
     * SoftDeletes trait trì hoãn sang Phase 3.
     * deleted_at column có sẵn; $task->delete() vẫn hard delete như hiện tại.
     */

    public const TYPE_REWRITE = 'rewrite';

    public const TYPE_NEW_KEYWORD = 'new_keyword';

    public const TYPE_NEW_TITLE = 'new_title';

    public const TYPE_IMPROVE = 'improve';

    public const REWRITE_MODE_KEYWORD = 'keyword';

    public const REWRITE_MODE_CONTENT = 'content';

    public const POST_TYPE_ARTICLE = 'article';

    public const POST_TYPE_PRODUCT = 'product';

    public const POST_TYPE_CATEGORY = 'category';

    public const POST_TYPE_PRODUCT_CATEGORY = 'product_category';

    public const STATUS_PENDING = 'pending';

    public const STATUS_WRITING = 'writing';

    public const STATUS_REVIEWING = 'reviewing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_project_tasks';

    protected $guarded = [];

    protected $casts = [
        'site_id' => 'integer',
        'article_id' => 'integer',
        'archived_from_project_id' => 'integer',
        'target_date' => 'date',
        'connected_at' => 'datetime',
        'completed_at' => 'datetime',
        'archived_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsToOnDefaultConnection(Site::class, 'site_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(SeoProject::class, 'project_id');
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(SeoArticle::class, 'article_id');
    }

    public function runItems(): HasMany
    {
        return $this->hasMany(SeoProjectRunItem::class, 'task_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(SeoProjectTaskEvent::class, 'task_id');
    }

    /**
     * @return array<string, string>
     */
    public static function rewriteModeOptions(): array
    {
        return [
            self::REWRITE_MODE_KEYWORD => __('seo-content-ai::filament.projects.rewrite_mode_keyword'),
            self::REWRITE_MODE_CONTENT => __('seo-content-ai::filament.projects.rewrite_mode_content'),
        ];
    }

    public static function normalizeRewriteMode(mixed $value): string
    {
        $normalized = trim((string) $value);

        return in_array($normalized, [self::REWRITE_MODE_KEYWORD, self::REWRITE_MODE_CONTENT], true)
            ? $normalized
            : self::REWRITE_MODE_KEYWORD;
    }

    /**
     * @return array<string, string>
     */
    public static function typeOptions(): array
    {
        return [
            self::TYPE_NEW_KEYWORD => 'Viết mới (Từ khóa)',
            self::TYPE_NEW_TITLE => 'Viết mới (Tiêu đề)',
            self::TYPE_REWRITE => 'Viết lại (Sửa bài lỗi)',
            self::TYPE_IMPROVE => __('seo-content-ai::filament.projects.type_improve'),
        ];
    }

    /**
     * @return list<string>
     */
    public static function typeKeys(): array
    {
        return [
            self::TYPE_REWRITE,
            self::TYPE_NEW_KEYWORD,
            self::TYPE_NEW_TITLE,
            self::TYPE_IMPROVE,
        ];
    }

    /**
     * @return list<string>
     */
    public static function newArticleTypes(): array
    {
        return [self::TYPE_NEW_KEYWORD, self::TYPE_NEW_TITLE];
    }

    public static function isNewArticleType(mixed $type): bool
    {
        return in_array((string) $type, self::newArticleTypes(), true);
    }

    public static function isManualRunType(string $type): bool
    {
        return $type === self::TYPE_IMPROVE;
    }

    /**
     * @return list<string>
     */
    public static function articlePickerTypes(): array
    {
        return [self::TYPE_REWRITE, self::TYPE_IMPROVE];
    }

    /**
     * @return list<string>
     */
    public static function postTypeKeys(): array
    {
        return [
            self::POST_TYPE_ARTICLE,
            self::POST_TYPE_PRODUCT,
            self::POST_TYPE_CATEGORY,
            self::POST_TYPE_PRODUCT_CATEGORY,
        ];
    }

    public static function normalizePostType(mixed $value): string
    {
        $normalized = trim((string) $value);

        return in_array($normalized, self::postTypeKeys(), true)
            ? $normalized
            : self::POST_TYPE_ARTICLE;
    }

    /**
     * @return array<string, string>
     */
    public static function statusOptions(): array
    {
        return [
            self::STATUS_PENDING => 'Chờ làm',
            self::STATUS_WRITING => 'Đang viết',
            self::STATUS_REVIEWING => 'Đang duyệt',
            self::STATUS_COMPLETED => 'Hoàn thành',
            self::STATUS_FAILED => 'Lỗi',
        ];
    }
}
