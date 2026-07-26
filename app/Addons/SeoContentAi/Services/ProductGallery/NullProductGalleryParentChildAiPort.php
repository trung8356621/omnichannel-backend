<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\ProductGallery;

use App\Addons\SeoContentAi\Contracts\ProductGalleryParentChildAiPort;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Models\SeoMedia;
use App\Addons\SeoContentAi\Support\ProductGallery\ProductGalleryGlobalContext;
use App\Addons\SeoContentAi\Support\ProductGallery\ProductGalleryShotDefinition;

/**
 * Scaffold default — no live provider call. Production adapter replaces this binding.
 */
final class NullProductGalleryParentChildAiPort implements ProductGalleryParentChildAiPort
{
    public function runPlanner(SeoArticle $article, array $variables): string
    {
        throw new \RuntimeException('Mode 2 planner AI port not bound — scaffold only.');
    }

    public function generateParent(SeoArticle $article, array $variables): ?SeoMedia
    {
        throw new \RuntimeException('Mode 2 parent AI port not bound — scaffold only.');
    }

    public function generateChild(
        SeoArticle $article,
        SeoMedia $parent,
        ProductGalleryShotDefinition $shot,
        ProductGalleryGlobalContext $context,
        array $variables,
    ): ?SeoMedia {
        throw new \RuntimeException('Mode 2 child AI port not bound — scaffold only.');
    }
}
