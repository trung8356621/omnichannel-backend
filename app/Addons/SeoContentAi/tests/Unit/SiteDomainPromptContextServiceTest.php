<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Services\SiteDomainPromptContextService;
use PHPUnit\Framework\TestCase;

final class SiteDomainPromptContextServiceTest extends TestCase
{
    public function test_count_words_and_format_cta(): void
    {
        $service = new SiteDomainPromptContextService();

        $this->assertSame(3, $service->countWords('một hai ba'));
        $this->assertStringContainsString(
            'phone: 090',
            $service->formatCtaForPrompt([
                ['type' => 'phone', 'value' => '090'],
            ]),
        );
        $this->assertStringContainsString(
            'báo giá → https://example.com/bao-gia',
            $service->formatLinksForPrompt([
                ['keyword' => 'báo giá', 'link' => 'https://example.com/bao-gia'],
            ]),
        );
    }
}
