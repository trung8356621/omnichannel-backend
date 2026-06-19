<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Support\GoogleAiModelRegistry;
use PHPUnit\Framework\TestCase;

final class GoogleAiModelRegistryImagePriorityTest extends TestCase
{
    public function test_it_uses_custom_priority_list(): void
    {
        $models = GoogleAiModelRegistry::imageModelsToTry(
            preferred: null,
            excludeImagen: false,
            customPriority: [
                'gemini-2.5-pro-image',
                'gemini-2.5-flash-image',
                'imagen-4.0-generate-001',
            ],
            inputLength: 2000,
        );

        self::assertSame([
            'gemini-2.5-pro-image',
            'gemini-2.5-flash-image',
            'imagen-4.0-generate-001',
        ], $models);
    }

    public function test_it_excludes_imagen_for_product_context(): void
    {
        $models = GoogleAiModelRegistry::imageModelsToTry(
            preferred: null,
            excludeImagen: true,
            customPriority: GoogleAiModelRegistry::defaultImageModelPriority(),
        );

        self::assertNotContains('imagen-4.0-generate-001', $models);
        self::assertContains('gemini-2.5-flash-image', $models);
    }
}
