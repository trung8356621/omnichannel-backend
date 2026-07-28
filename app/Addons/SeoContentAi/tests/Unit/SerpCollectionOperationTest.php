<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Enums\Serp\SerpSnapshotStatus;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Support\ContentProjectBusinessLock;
use App\Addons\SeoContentAi\Services\SerpIntelligence\SerpCollectionLockService;
use PHPUnit\Framework\TestCase;

final class SerpCollectionOperationTest extends TestCase
{
    public function test_collection_lock_key_format(): void
    {
        $lock = new SerpCollectionLockService(new ContentProjectBusinessLock);
        $ref = KeywordIntelligencePublicRefHelper::serpQueryRef();

        self::assertSame('serp-collection:'.$ref, $lock->collectionKey($ref));
        self::assertSame('serp-workspace-analysis:kww_test', $lock->workspaceAnalysisKey('kww_test'));
    }

    public function test_collect_operation_service_uses_collection_lock(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/Services/SerpIntelligence/SerpCollectionOperationService.php',
        );

        self::assertStringContainsString('withCollectionLock', $source);
        self::assertStringContainsString('SerpCollectionLockService', $source);
    }

    public function test_snapshot_status_stages_list_matches_operation_lifecycle(): void
    {
        $stages = array_map(static fn (SerpSnapshotStatus $s): string => $s->value, SerpSnapshotStatus::cases());

        self::assertContains('pending', $stages);
        self::assertContains('collecting', $stages);
        self::assertContains('normalizing', $stages);
        self::assertContains('analyzing', $stages);
        self::assertContains('completed', $stages);
        self::assertContains('failed', $stages);
    }
}

/** @internal */
final class KeywordIntelligencePublicRefHelper
{
    public static function serpQueryRef(): string
    {
        return \App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\KeywordIntelligencePublicRef::serpQuery(99);
    }
}
