<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Support\GoogleAiModelRegistry;
use App\Addons\SeoContentAi\Support\ImageModelInputLengthPolicy;
use PHPUnit\Framework\TestCase;

final class ImageModelInputLengthPolicyTest extends TestCase
{
    public function test_it_measures_rendered_prompt_length(): void
    {
        self::assertSame(11, ImageModelInputLengthPolicy::measureCompiledPromptLength('  hello world '));
    }

    public function test_it_prefers_flash_tier_for_short_input(): void
    {
        $models = ImageModelInputLengthPolicy::reorderModels(
            GoogleAiModelRegistry::defaultImageModelPriority(),
            500,
        );

        self::assertSame('gemini-2.5-flash-image', $models[0]);
        self::assertSame('gemini-2.5-pro-image', $models[1]);
    }

    public function test_it_prefers_pro_tier_for_long_input(): void
    {
        $models = ImageModelInputLengthPolicy::reorderModels(
            GoogleAiModelRegistry::defaultImageModelPriority(),
            1500,
        );

        self::assertSame('gemini-2.5-pro-image', $models[0]);
        self::assertSame('gemini-2.5-flash-image', $models[1]);
    }

    public function test_registry_applies_input_length_to_custom_priority(): void
    {
        $models = GoogleAiModelRegistry::imageModelsToTry(
            preferred: null,
            excludeImagen: false,
            customPriority: [
                'imagen-4.0-generate-001',
                'gemini-2.5-pro-image',
                'gemini-2.5-flash-image',
            ],
            inputLength: 200,
        );

        self::assertSame([
            'gemini-2.5-flash-image',
            'gemini-2.5-pro-image',
            'imagen-4.0-generate-001',
        ], $models);
    }
}
