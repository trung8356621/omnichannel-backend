<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Models;

use App\Addons\SeoContentAi\Models\Concerns\BelongsToOnDefaultConnection;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Bản ghi nội dung SEO trên DB addon (bảng `articles`, connection `omi_seo_ai`).
 */
class SeoArticle extends Model
{
    use BelongsToOnDefaultConnection;
    use SoftDeletes;

    protected $connection = 'omi_seo_ai';

    /** @var string Bảng vật lý là `articles` (SEO / bài viết đồng bộ). */
    protected $table = 'articles';

    protected $guarded = [];

    protected $casts = [
        'blocks'       => 'array',
        'seo_score'    => 'decimal:2',
        'published_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsToOnDefaultConnection(Site::class, 'site_id');
    }

    /**
     * Tất cả kết quả prompt gắn với bài viết (phân loại bằng pivot `type`).
     */
    public function promptResults(): MorphToMany
    {
        return $this->morphToMany(
            SeoPromptResult::class,
            'prompt_resultable',
            'seo_prompt_resultables',
        )
            ->withPivot('type')
            ->withTimestamps();
    }

    public function getPromptResultByType(string $type): ?SeoPromptResult
    {
        $result = $this->promptResults()
            ->wherePivot('type', $type)
            ->latest('seo_prompt_resultables.id')
            ->first();

        return $result instanceof SeoPromptResult ? $result : null;
    }

    public function attachPromptResult(int $promptResultId, string $type): void
    {
        $this->promptResults()
            ->wherePivot('type', $type)
            ->detach();

        $this->promptResults()->attach($promptResultId, ['type' => $type]);
    }

    public function keywords(): BelongsToMany
    {
        return $this->belongsToMany(Keyword::class, 'article_keyword', 'article_id', 'keyword_id')
            ->withPivot('weight')
            ->withTimestamps();
    }

    public function articleMetas(): HasMany
    {
        return $this->hasMany(ArticleMeta::class, 'article_id');
    }

    public function articleKeywords(): HasMany
    {
        return $this->hasMany(ArticleKeyword::class, 'article_id');
    }

    /**
     * @return array{internal: array<int, mixed>, external: array<int, mixed>}
     */
    public function resolveExtractedLinks(): array
    {
        $raw = null;

        if ($this->relationLoaded('articleMetas')) {
            $meta = $this->articleMetas->firstWhere('meta_key', 'seo_extracted_links');
            $raw = $meta?->meta_value;
        } else {
            $raw = $this->articleMetas()
                ->where('meta_key', 'seo_extracted_links')
                ->value('meta_value');
        }

        if (! is_string($raw) || trim($raw) === '') {
            return ['internal' => [], 'external' => []];
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return ['internal' => [], 'external' => []];
        }

        return [
            'internal' => is_array($decoded['internal'] ?? null) ? $decoded['internal'] : [],
            'external' => is_array($decoded['external'] ?? null) ? $decoded['external'] : [],
        ];
    }

    public function getInternalLinkCountAttribute(): int
    {
        if (array_key_exists('internal_link_count', $this->attributes) && $this->attributes['internal_link_count'] !== null) {
            return (int) $this->attributes['internal_link_count'];
        }

        return count($this->resolveExtractedLinks()['internal']);
    }

    public function getExternalLinkCountAttribute(): int
    {
        if (array_key_exists('external_link_count', $this->attributes) && $this->attributes['external_link_count'] !== null) {
            return (int) $this->attributes['external_link_count'];
        }

        return count($this->resolveExtractedLinks()['external']);
    }
}
