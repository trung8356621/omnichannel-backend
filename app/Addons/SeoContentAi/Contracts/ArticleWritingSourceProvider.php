<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Contracts;

use App\Addons\SeoContentAi\Enums\ArticleWritingSourceType;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Support\ArticleGenerationSourceResult;
use App\Addons\SeoContentAi\Support\ArticleWritingInput;

interface ArticleWritingSourceProvider
{
    public function sourceType(): ArticleWritingSourceType;

    /**
     * @param  array<string, mixed>  $variables
     */
    public function resolve(
        array $variables,
        ?SeoArticle $article = null,
        ?ArticleGenerationSourceResult $outlineFromWorkflow = null,
    ): ArticleWritingInput;
}
