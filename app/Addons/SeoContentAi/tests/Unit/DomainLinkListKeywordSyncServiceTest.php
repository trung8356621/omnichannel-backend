<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Services\SiteDomainPromptContextService;
use PHPUnit\Framework\TestCase;

final class DomainLinkListKeywordSyncServiceTest extends TestCase
{
    public function test_prompt_variables_never_include_link_list_text(): void
    {
        $service = new SiteDomainPromptContextService();

        $vars = $service->promptVariablesForSite(null);

        $this->assertSame('', $vars['site_links']);
    }
}
