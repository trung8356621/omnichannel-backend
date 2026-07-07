<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Services\SeoPromptSettingsService;
use PHPUnit\Framework\TestCase;

final class SeoPromptSettingsArticleLengthTest extends TestCase
{
    public function test_parse_article_length_target_extracts_first_integer(): void
    {
        $this->assertSame(2000, SeoPromptSettingsService::parseArticleLengthTarget('2000'));
        $this->assertSame(1500, SeoPromptSettingsService::parseArticleLengthTarget('khoảng 1500 từ'));
    }

    public function test_parse_article_length_target_falls_back_when_missing_digits(): void
    {
        $this->assertSame(1000, SeoPromptSettingsService::parseArticleLengthTarget('không rõ', 1000));
    }
}
