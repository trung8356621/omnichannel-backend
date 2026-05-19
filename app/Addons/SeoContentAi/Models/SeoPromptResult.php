<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Models;

use Illuminate\Database\Eloquent\Relations\MorphToMany;

/**
 * Kết quả chạy prompt trên DB addon (`prompt_results`, connection `omi_seo_ai`).
 */
class SeoPromptResult extends PromptResult
{
    /**
     * Các bài viết (và ngữ cảnh pivot `type`) gắn với kết quả prompt này.
     */
    public function articles(): MorphToMany
    {
        return $this->morphedByMany(
            SeoArticle::class,
            'prompt_resultable',
            'seo_prompt_resultables',
        )
            ->withPivot('type')
            ->withTimestamps();
    }

    /**
     * Lọc bài viết theo ngữ cảnh pivot (VD: outline, content, review_product).
     */
    public function articlesOfType(string $type): MorphToMany
    {
        return $this->articles()->wherePivot('type', $type);
    }
}
