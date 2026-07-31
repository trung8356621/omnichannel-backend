<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

/**
 * @deprecated Use {@see SeoApiConnectionProviderCatalog}.
 * Name collided with Extension\Registry\SeoProviderRegistry (SDK drivers).
 * This subclass keeps FQCN compatibility for existing DI/imports.
 */
final class SeoProviderRegistry extends SeoApiConnectionProviderCatalog
{
}
