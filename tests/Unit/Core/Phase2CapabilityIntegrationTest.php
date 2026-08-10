<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Automation\ActionRegistry;
use App\Core\Automation\ConditionEngine;
use App\Core\Automation\TriggerRegistry;
use App\Core\Capability\CapabilityRegistry;
use App\Core\Capability\Contracts\KeywordIdentityCapability;
use App\Core\Capability\Contracts\PublisherCapability;
use Omnichannel\Addons\SearchFoundation\CanonicalKeywordIdentity;
use PHPUnit\Framework\TestCase;

final class Phase2CapabilityIntegrationTest extends TestCase
{
    public function test_publisher_capability_port_exists_without_wordpress_import(): void
    {
        $this->assertTrue(interface_exists(PublisherCapability::class));
        $ref = new \ReflectionClass(PublisherCapability::class);
        $this->assertSame('App\\Core\\Capability\\Contracts\\PublisherCapability', $ref->getName());
    }

    public function test_search_foundation_identity_implements_core_contract(): void
    {
        $identity = new CanonicalKeywordIdentity('sqlite');
        $this->assertInstanceOf(KeywordIdentityCapability::class, $identity);
    }

    public function test_seo_and_intelligence_can_share_registry_without_importing_each_other(): void
    {
        $caps = new CapabilityRegistry();
        $caps->register('search.keyword', new CanonicalKeywordIdentity('sqlite'), 'search-foundation');
        $caps->register('seo.audit', (object) ['owner' => 'seo'], 'seo');
        $caps->register('search.intelligence', (object) ['owner' => 'si'], 'search-intelligence');

        $kw = $caps->getAs('search.keyword', KeywordIdentityCapability::class);
        $this->assertInstanceOf(KeywordIdentityCapability::class, $kw);
        $this->assertSame('search-foundation', $caps->ownerOf('search.keyword'));
        $this->assertSame('seo', $caps->ownerOf('seo.audit'));
        $this->assertSame('search-intelligence', $caps->ownerOf('search.intelligence'));
    }

    public function test_automation_registries_accept_addon_triggers_and_actions(): void
    {
        $triggers = new TriggerRegistry();
        $actions = new ActionRegistry();
        $conditions = new ConditionEngine();

        $triggers->register('content_project.item_ready', 'content-projects', static fn () => ['ok' => true]);
        $actions->register('publishing.enqueue', 'publishing', static fn () => ['queued' => true]);
        $conditions->register('site_active', static fn (array $ctx): bool => ($ctx['active'] ?? false) === true);

        $this->assertTrue($triggers->has('content_project.item_ready'));
        $this->assertSame('publishing', $actions->ownerOf('publishing.enqueue'));
        $this->assertTrue($conditions->evaluate([['name' => 'site_active', 'params' => ['active' => true]]]));
        $this->assertFalse($conditions->evaluate([['name' => 'site_active', 'params' => ['active' => false]]]));
    }
}
