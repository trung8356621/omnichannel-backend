<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Services\ArticleCtaPlaceholderService;
use App\Addons\SeoContentAi\Services\SiteDomainPromptContextService;
use PHPUnit\Framework\TestCase;

final class ArticleCtaPlaceholderServiceTest extends TestCase
{
    public function test_placeholder_guide_lists_all_types(): void
    {
        $guide = (new ArticleCtaPlaceholderService(new SiteDomainPromptContextService()))
            ->placeholderGuideForPrompt();

        foreach (array_keys(ArticleCtaPlaceholderService::PLACEHOLDER_TYPES) as $type) {
            $this->assertStringContainsString("[{$type}]", $guide);
        }

        $this->assertStringContainsString('[Website/Hotline]', $guide);
    }

    public function test_format_cta_for_prompt_always_includes_guide(): void
    {
        $service = new SiteDomainPromptContextService();

        $text = $service->formatCtaForPrompt([], '');

        $this->assertStringContainsString('[phone]', $text);
        $this->assertStringContainsString('[website]', $text);
    }

    public function test_replace_without_site_leaves_placeholders(): void
    {
        $service = new ArticleCtaPlaceholderService(new SiteDomainPromptContextService());

        $this->assertSame(
            '<p>[phone]</p>',
            $service->replaceInHtml('<p>[phone]</p>', null),
        );
    }
}
