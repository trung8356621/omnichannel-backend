<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\ProductGallery;

use App\Addons\SeoContentAi\Support\ProductGallery\ProductGalleryGenerationMode;
use App\Addons\SeoContentAi\Support\ProductGallery\ProductGalleryModeResolution;

/**
 * Thin Mode 1 / Mode 2 router — no Mode 1 refactor.
 */
final class ProductGalleryModeOrchestrator
{
    public function __construct(
        private readonly ImageProviderCapabilityResolver $capabilities,
        private readonly ProductGalleryGenerationModeResolver $modeResolver,
    ) {}

    /**
     * @return array{
     *     route: 'sprite'|'parent_child'|'manual',
     *     resolution: ProductGalleryModeResolution,
     *     supports_reference_image: bool
     * }
     */
    public function decide(string $configuredMode, ?string $provider, ?string $model): array
    {
        $caps = $this->capabilities->resolve($provider, $model);
        $resolution = $this->modeResolver->resolve($configuredMode, $caps);

        $route = match ($resolution->resolved) {
            ProductGalleryGenerationMode::ParentChild => 'parent_child',
            ProductGalleryGenerationMode::Manual => 'manual',
            default => 'sprite',
        };

        return [
            'route' => $route,
            'resolution' => $resolution,
            'supports_reference_image' => $caps->supportsReferenceImage,
        ];
    }
}
