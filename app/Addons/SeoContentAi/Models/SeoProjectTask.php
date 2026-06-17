<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Models;

use App\Addons\SeoContentAi\Models\Concerns\BelongsToOnDefaultConnection;
use App\Models\Site;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoProjectTask extends Model
{
    use BelongsToOnDefaultConnection;

    public const TYPE_REWRITE = 'rewrite';

    public const TYPE_NEW_KEYWORD = 'new_keyword';

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
        'target_date' => 'date',
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
            self::TYPE_REWRITE => 'Viết lại (Sửa bài lỗi)',
        ];
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
