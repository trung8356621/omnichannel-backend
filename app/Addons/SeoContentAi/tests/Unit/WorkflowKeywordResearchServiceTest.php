<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Services\WorkflowKeywordResearchService;
use PHPUnit\Framework\TestCase;

final class WorkflowKeywordResearchServiceTest extends TestCase
{
    public function test_should_sync_keywords_for_dedicated_action(): void
    {
        $service = new WorkflowKeywordResearchService();
        $state = new \App\Addons\SeoContentAi\Support\WorkflowExecutionState();

        $this->assertTrue($service->shouldSyncKeywords('save_vocabulary_research', $state));
    }

    public function test_should_sync_when_parsed_groups_exist(): void
    {
        $service = new WorkflowKeywordResearchService();
        $state = new \App\Addons\SeoContentAi\Support\WorkflowExecutionState();
        $state->setParsedKeywords(['Synonyms' => ['xưởng may']]);

        $this->assertTrue($service->shouldSyncKeywords('create_article', $state));
    }
}
