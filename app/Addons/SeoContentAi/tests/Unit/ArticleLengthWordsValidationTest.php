<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\PromptHooks\Exceptions\InvalidOutput;
use App\Addons\SeoContentAi\PromptHooks\Exceptions\OutputTruncated;
use App\Addons\SeoContentAi\PromptHooks\Output\PromptHookRuntimeOutputPipeline;
use App\Addons\SeoContentAi\PromptHooks\Runtime\PromptHookDefinitionLoader;
use App\Addons\SeoContentAi\Services\SeoPromptSettingsService;
use App\Addons\SeoContentAi\Support\PromptTextMetrics;
use PHPUnit\Framework\TestCase;

final class ArticleLengthWordsValidationTest extends TestCase
{
    public function test_word_count_matches_editor_whitespace_split(): void
    {
        self::assertSame(0, PromptTextMetrics::wordCount(''));
        self::assertSame(3, PromptTextMetrics::wordCount("  a  b\nc  "));
        self::assertSame(5, PromptTextMetrics::wordCount('một hai ba bốn năm'));
    }

    public function test_min_words_from_article_length_equals_resolved_target(): void
    {
        self::assertSame(1000, PromptTextMetrics::minWordsFromArticleLength(1000));
        self::assertSame(2000, PromptTextMetrics::minWordsFromArticleLength(2000));
        self::assertSame(1800, PromptTextMetrics::minWordsFromArticleLength(1800));
        self::assertSame(300, PromptTextMetrics::minWordsFromArticleLength(0));
    }

    public function test_prompt_variables_resolve_product_1000_and_other_2000(): void
    {
        $service = SeoPromptSettingsService::withDefaults();

        self::assertSame('1000', $service->promptVariables('product')['article_length']);
        self::assertSame('2000', $service->promptVariables('article')['article_length']);
        self::assertSame('2000', $service->promptVariables('post')['article_length']);
        self::assertSame(1000, $service->resolveArticleLengthTarget('product'));
        self::assertSame(2000, $service->resolveArticleLengthTarget('article'));
    }

    public function test_content_hooks_declare_length_unit_words(): void
    {
        $dir = dirname(__DIR__, 2).'/resources/prompt-hooks/v01';
        foreach (['article.content.generate@0.1.0.json', 'article.content.rewrite@0.1.0.json'] as $file) {
            $json = json_decode((string) file_get_contents($dir.'/'.$file), true);
            self::assertIsArray($json);
            self::assertSame('words', $json['output_schema']['validation']['length_unit'] ?? null, $file);
            self::assertSame('words', $json['metadata']['article_length_unit'] ?? null, $file);
        }
    }

    public function test_output_pipeline_words_rejects_short_even_if_many_chars(): void
    {
        $pipeline = new PromptHookRuntimeOutputPipeline;
        $def = $this->markdownWordsDefinition(300);

        // ~60 words nhưng >400 ký tự (padding) — vẫn fail theo từ.
        $words = [];
        for ($i = 0; $i < 60; $i++) {
            $words[] = 'từkhoádài'.str_repeat('x', 5);
        }
        $text = implode(' ', $words);
        self::assertGreaterThan(400, mb_strlen($text));
        self::assertSame(60, PromptTextMetrics::wordCount($text));

        $this->expectException(OutputTruncated::class);
        $pipeline->process($def, ['text' => $text], null, ['article_length' => 1000]);
    }

    public function test_output_pipeline_words_passes_at_exact_article_length(): void
    {
        $pipeline = new PromptHookRuntimeOutputPipeline;
        $def = $this->markdownWordsDefinition(300);

        $text = trim(str_repeat('word ', 1000));
        self::assertSame(1000, PromptTextMetrics::wordCount($text));

        $out = $pipeline->process($def, ['text' => $text], null, ['article_length' => 1000]);
        self::assertSame($text, $out['value']);
    }

    public function test_output_pipeline_words_fails_below_exact_article_length(): void
    {
        $pipeline = new PromptHookRuntimeOutputPipeline;
        $def = $this->markdownWordsDefinition(300);

        $text = trim(str_repeat('word ', 999));
        $this->expectException(OutputTruncated::class);
        $this->expectExceptionMessage('999 words < 1000 words');
        $pipeline->process($def, ['text' => $text], null, ['article_length' => 1000]);
    }

    public function test_output_pipeline_words_uses_updated_settings_target(): void
    {
        $pipeline = new PromptHookRuntimeOutputPipeline;
        $def = $this->markdownWordsDefinition(300);

        $text = trim(str_repeat('word ', 1800));
        $out = $pipeline->process($def, ['text' => $text], null, ['article_length' => 1800]);
        self::assertSame($text, $out['value']);

        $this->expectException(OutputTruncated::class);
        $this->expectExceptionMessage('1800 words < 2000 words');
        $pipeline->process($def, ['text' => $text], null, ['article_length' => 2000]);
    }

    public function test_output_pipeline_words_fails_500_words_when_target_1000(): void
    {
        $pipeline = new PromptHookRuntimeOutputPipeline;
        $def = $this->markdownWordsDefinition(300);

        $text = trim(str_repeat('word ', 500));
        $this->expectException(OutputTruncated::class);
        $pipeline->process($def, ['text' => $text], null, ['article_length' => 1000]);
    }

    public function test_output_pipeline_chars_still_used_when_unit_missing(): void
    {
        $pipeline = new PromptHookRuntimeOutputPipeline;
        $loader = new PromptHookDefinitionLoader(
            dirname(__DIR__, 2).'/resources/prompt-hooks/v01',
            dirname(__DIR__, 2).'/resources/prompt-hooks',
        );
        $def = $loader->hydrateSpecV01([
            'spec_version' => '0.1',
            'key' => 'article.test.chars',
            'version' => '0.1.0',
            'enabled' => true,
            'model' => ['settings' => []],
            'locale' => ['mode' => 'site', 'fallback' => 'en'],
            'input_schema' => [],
            'output_schema' => [
                'type' => 'text',
                'validation' => [
                    'not_empty' => true,
                    'minimum_length' => 50,
                    'max_length' => 80,
                ],
                'normalize' => ['trim'],
            ],
            'template' => ['system' => 's', 'user' => 'u'],
            'side_effects' => [],
        ]);

        $ok = $pipeline->process($def, ['text' => str_repeat('a', 60)]);
        self::assertSame(60, mb_strlen((string) $ok['value']));

        $this->expectException(InvalidOutput::class);
        $pipeline->process($def, ['text' => str_repeat('a', 90)]);
    }

    private function markdownWordsDefinition(int $minimumLength): \App\Addons\SeoContentAi\PromptHooks\Canonical\PromptHookDefinition
    {
        $loader = new PromptHookDefinitionLoader(
            dirname(__DIR__, 2).'/resources/prompt-hooks/v01',
            dirname(__DIR__, 2).'/resources/prompt-hooks',
        );

        return $loader->hydrateSpecV01([
            'spec_version' => '0.1',
            'key' => 'article.test.words',
            'version' => '0.1.0',
            'enabled' => true,
            'model' => ['settings' => []],
            'locale' => ['mode' => 'site', 'fallback' => 'en'],
            'input_schema' => [
                'article_length' => [
                    'type' => 'integer',
                    'required' => false,
                    'nullable' => true,
                ],
            ],
            'output_schema' => [
                'type' => 'markdown',
                'validation' => [
                    'not_empty' => true,
                    'length_unit' => 'words',
                    'minimum_length' => $minimumLength,
                ],
                'normalize' => ['trim'],
            ],
            'template' => ['system' => 's', 'user' => 'u'],
            'side_effects' => [],
        ]);
    }
}
