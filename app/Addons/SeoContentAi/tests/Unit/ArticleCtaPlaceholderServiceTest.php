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

    public function test_detect_placeholder_types_is_case_insensitive_and_scans_faqs(): void
    {
        $service = new ArticleCtaPlaceholderService(new SiteDomainPromptContextService());

        $types = $service->detectPlaceholderTypes(
            '<p>Liên hệ [PHONE] hoặc ghé [Address].</p>',
            'Gọi [email] để nhận tư vấn',
        );

        sort($types);

        $this->assertSame(['address', 'email', 'phone'], $types);
    }

    public function test_apply_for_publish_without_site_is_noop(): void
    {
        $service = new ArticleCtaPlaceholderService(new SiteDomainPromptContextService());

        $result = $service->applyForPublish(null, '<p>[address]</p>', [
            ['question' => 'A?', 'answer' => '<p>[phone]</p>'],
        ]);

        $this->assertSame('<p>[address]</p>', $result['html']);
        $this->assertSame([], $result['added_blank_types']);
    }

    public function test_strip_blank_placeholder_markup_restores_brackets(): void
    {
        $service = new ArticleCtaPlaceholderService(new SiteDomainPromptContextService());

        $html = '<p><span class="seo-cta-blank-placeholder" data-cta-type="website">[website]</span></p>';
        $stripped = $service->stripBlankPlaceholderMarkup($html);

        $this->assertSame('<p>[website]</p>', $stripped);
        $this->assertStringNotContainsString('seo-cta-blank-placeholder', $stripped);
    }
}
