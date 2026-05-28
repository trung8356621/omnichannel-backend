<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Models;

use App\Addons\SeoContentAi\Models\Concerns\BelongsToOnDefaultConnection;
use App\Models\Site;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoMedia extends Model
{
    use BelongsToOnDefaultConnection;

    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_media';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'wp_attachment_id' => 'integer',
            'wp_synced_at' => 'datetime',
            'prompt_id' => 'integer',
            'prompt_variables' => 'array',
        ];
    }

    public function isAiGenerationJob(): bool
    {
        $source = strtolower(trim((string) $this->source));

        return in_array($source, ['ai_prompt', 'ai_video_prompt'], true);
    }

    public function aiToolType(): string
    {
        return strtolower(trim((string) $this->source)) === 'ai_video_prompt' ? 'video' : 'image';
    }

    public static function placeholderLoadingUrl(): string
    {
        return '/assets/images/placeholder-loading.svg';
    }

    public static function placeholderLoadingPath(): string
    {
        return 'assets/images/placeholder-loading.svg';
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(SeoArticle::class, 'article_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsToOnDefaultConnection(Site::class, 'site_id');
    }

    public function publicUrl(): string
    {
        $path = ltrim(str_replace('\\', '/', (string) $this->path), '/');
        if ($path !== '') {
            return '/storage/' . $path;
        }

        $url = (string) $this->url;
        if (str_starts_with($url, '/storage/')) {
            return $url;
        }

        return $url;
    }
}
