<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\PromptHooks\PromptHookFormSchema;
use App\Addons\SeoContentAi\PromptHooks\PromptHookManifestLoader;
use App\Addons\SeoContentAi\PromptHooks\PromptHookRegistry;
use App\Addons\SeoContentAi\Support\ImageToolType;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\TestCase;

final class PromptHookFormSchemaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('app')) {
            self::markTestSkipped('Laravel app() helper required.');
        }

        try {
            app()->instance(
                PromptHookRegistry::class,
                new PromptHookRegistry(
                    new PromptHookManifestLoader(PromptHookManifestLoader::defaultDirectory(), true),
                ),
            );
        } catch (\Throwable) {
            self::markTestSkipped('Laravel container not available.');
        }
    }

    public function test_normalize_clears_hook_when_empty(): void
    {
        $data = PromptHookFormSchema::normalizeForSave([
            'hook_key' => '',
            'hook_version' => 1,
            'hook_settings' => ['max_length' => 65],
            'tools' => 'default',
        ]);

        self::assertNull($data['hook_key']);
        self::assertNull($data['hook_version']);
        self::assertNull($data['hook_settings']);
    }

    public function test_normalize_sets_version_and_settings_from_manifest(): void
    {
        $data = PromptHookFormSchema::normalizeForSave([
            'hook_key' => 'article.title_suggestion',
            'hook_settings' => ['max_length' => 70, 'garbage' => 1],
            'tools' => 'default',
        ]);

        self::assertSame('article.title_suggestion', $data['hook_key']);
        self::assertSame(1, $data['hook_version']);
        self::assertSame(70, $data['hook_settings']['max_length']);
        self::assertTrue($data['hook_settings']['preserve_meaning']);
        self::assertArrayNotHasKey('garbage', $data['hook_settings']);
    }

    public function test_normalize_rejects_image_tool_for_text_hook(): void
    {
        $this->expectException(ValidationException::class);

        PromptHookFormSchema::normalizeForSave([
            'hook_key' => 'article.title_suggestion',
            'tools' => ImageToolType::Image->value,
            'hook_settings' => [],
        ]);
    }
}
