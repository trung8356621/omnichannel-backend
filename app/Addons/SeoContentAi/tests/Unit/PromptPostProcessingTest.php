<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Support\PromptPostProcessing;
use PHPUnit\Framework\TestCase;

final class PromptPostProcessingTest extends TestCase
{
    public function test_normalize_clamps_grid_and_booleans(): void
    {
        $normalized = PromptPostProcessing::normalize([
            'split_enabled' => '1',
            'split_rows' => 99,
            'split_columns' => 0,
            'resize_enabled' => false,
            'resize_width' => '800',
            'resize_height' => '',
        ]);

        $this->assertTrue($normalized['split_enabled']);
        $this->assertSame(12, $normalized['split_rows']);
        $this->assertSame(2, $normalized['split_columns']);
        $this->assertFalse($normalized['resize_enabled']);
        $this->assertSame(800, $normalized['resize_width']);
        $this->assertNull($normalized['resize_height']);
    }

    public function test_merge_into_settings_preserves_other_keys(): void
    {
        $merged = PromptPostProcessing::mergeIntoSettings(
            ['detected_tags' => ['image']],
            ['split_enabled' => true, 'split_rows' => 4, 'split_columns' => 3],
        );

        $this->assertSame(['image'], $merged['detected_tags']);
        $this->assertTrue($merged['post_processing']['split_enabled']);
        $this->assertSame(4, $merged['post_processing']['split_rows']);
        $this->assertSame(3, $merged['post_processing']['split_columns']);
    }

    public function test_is_active_requires_resize_dimensions(): void
    {
        $this->assertFalse(PromptPostProcessing::isActive([
            'split_enabled' => false,
            'split_rows' => 3,
            'split_columns' => 2,
            'resize_enabled' => true,
            'resize_width' => null,
            'resize_height' => null,
        ]));

        $this->assertTrue(PromptPostProcessing::isActive([
            'split_enabled' => false,
            'split_rows' => 3,
            'split_columns' => 2,
            'resize_enabled' => true,
            'resize_width' => 800,
            'resize_height' => null,
        ]));
    }
}
