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

    public function test_partition_keyword_groups_extracts_related_topics(): void
    {
        $service = new WorkflowKeywordResearchService();

        [$clusterGroups, $relatedTopics] = $service->partitionKeywordGroups([
            'Synonyms' => ['ba lô học sinh'],
            'Related topics' => [
                'Cách chọn cặp theo phong thủy',
                'Review các loại balo học sinh',
            ],
            'RELATED TOPICS' => ['Top quà tặng cho người cung Cự Giải'],
        ]);

        $this->assertSame(['ba lô học sinh'], $clusterGroups['Synonyms']);
        $this->assertCount(3, $relatedTopics);
        $this->assertSame('Cách chọn cặp theo phong thủy', $relatedTopics[0]);
        $this->assertArrayNotHasKey('Related topics', $clusterGroups);
    }
}
