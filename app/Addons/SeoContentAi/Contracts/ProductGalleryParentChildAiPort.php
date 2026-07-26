<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Contracts;

use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Models\SeoMedia;
use App\Addons\SeoContentAi\Support\ProductGallery\ProductGalleryGlobalContext;
use App\Addons\SeoContentAi\Support\ProductGallery\ProductGalleryShotDefinition;

/**
 * AI boundary for Mode 2 Parent/Child — injectable for tests / provider adapters.
 */
interface ProductGalleryParentChildAiPort
{
    /**
     * @param  array<string, mixed>  $variables
     */
    public function runPlanner(SeoArticle $article, array $variables): string;

    /**
     * @param  array<string, mixed>  $variables
     */
    public function generateParent(SeoArticle $article, array $variables): ?SeoMedia;

    /**
     * @param  array<string, mixed>  $variables
     */
    public function generateChild(
        SeoArticle $article,
        SeoMedia $parent,
        ProductGalleryShotDefinition $shot,
        ProductGalleryGlobalContext $context,
        array $variables,
    ): ?SeoMedia;
}
