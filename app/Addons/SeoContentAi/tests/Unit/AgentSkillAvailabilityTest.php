<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Extension\ExtensionStateStore;
use App\Addons\SeoContentAi\Extension\Registry\ExtensionCapabilityRegistry;
use App\Addons\SeoContentAi\Services\AgentWorkspace\AgentSkillAvailabilityService;
use App\Addons\SeoContentAi\Services\AgentWorkspace\Dtos\AgentSkillAvailability;
use App\Addons\SeoContentAi\Services\AgentWorkspace\Dtos\AgentSkillDefinition;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Capabilities\CanonicalCapabilityRegistry;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Capabilities\ContentProjectCapabilityRegistry;
use PHPUnit\Framework\TestCase;

final class AgentSkillAvailabilityTest extends TestCase
{
    private AgentSkillAvailabilityService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new AgentSkillAvailabilityService(new CanonicalCapabilityRegistry(
            new ContentProjectCapabilityRegistry,
            new ExtensionCapabilityRegistry,
            new ExtensionStateStore,
        ));
    }

    public function test_meta_capability_with_status_override_is_available(): void
    {
        $skill = new AgentSkillDefinition(
            key: 'general.help',
            slashCommand: '/help',
            name: 'Help',
            description: 'Help',
            category: 'general',
            capability: 'agent.help',
            availabilityPolicy: ['status_override' => AgentSkillAvailability::AVAILABLE],
        );

        $result = $this->service->resolve($skill);

        self::assertSame(AgentSkillAvailability::AVAILABLE, $result->status);
        self::assertTrue($result->usable);
    }

    public function test_fake_capability_returns_not_implemented(): void
    {
        $skill = new AgentSkillDefinition(
            key: 'fake.skill',
            slashCommand: '/fake-skill',
            name: 'Fake',
            description: 'Fake',
            category: 'general',
            capability: 'does.not.exist',
        );

        $result = $this->service->resolve($skill);

        self::assertSame(AgentSkillAvailability::NOT_IMPLEMENTED, $result->status);
        self::assertFalse($result->usable);
    }

    public function test_read_capability_get_status_available_without_registry_entry(): void
    {
        $skill = new AgentSkillDefinition(
            key: 'content_project.status',
            slashCommand: '/project-status',
            name: 'Status',
            description: 'Status',
            category: 'content_project',
            capability: 'content_project.get_status',
            requiredScopes: ['content-project:read'],
        );

        $result = $this->service->resolve($skill, [
            'scopes' => ['content-project:read'],
        ]);

        self::assertSame(AgentSkillAvailability::AVAILABLE, $result->status);
        self::assertTrue($result->usable);
    }

    public function test_missing_required_scope_returns_permission_denied(): void
    {
        $skill = new AgentSkillDefinition(
            key: 'content_project.create',
            slashCommand: '/create-project',
            name: 'Create',
            description: 'Create',
            category: 'content_project',
            capability: 'content_project.create',
            requiredScopes: ['content-project:write'],
        );

        $result = $this->service->resolve($skill, ['scopes' => []]);

        self::assertSame(AgentSkillAvailability::PERMISSION_DENIED, $result->status);
        self::assertFalse($result->usable);
    }

    public function test_serp_provider_not_configured_returns_not_configured(): void
    {
        $skill = new AgentSkillDefinition(
            key: 'serp.collect',
            slashCommand: '/collect-serp',
            name: 'Collect SERP',
            description: 'Collect SERP',
            category: 'serp_intelligence',
            capability: 'serp_intelligence.collect',
            requiredScopes: ['content-project:write'],
            availabilityPolicy: ['provider' => 'serp'],
        );

        $result = $this->service->resolve($skill, [
            'scopes' => ['content-project:write'],
            'providers' => ['serp' => false],
        ]);

        self::assertSame(AgentSkillAvailability::NOT_CONFIGURED, $result->status);
        self::assertFalse($result->usable);
    }

    public function test_coming_soon_skill_returns_coming_soon(): void
    {
        $skill = new AgentSkillDefinition(
            key: 'future.skill',
            slashCommand: '/future-skill',
            name: 'Future',
            description: 'Future',
            category: 'general',
            capability: 'agent.help',
            isComingSoon: true,
        );

        $result = $this->service->resolve($skill);

        self::assertSame(AgentSkillAvailability::COMING_SOON, $result->status);
        self::assertFalse($result->usable);
    }

    public function test_hidden_skill_returns_hidden(): void
    {
        $skill = new AgentSkillDefinition(
            key: 'hidden.skill',
            slashCommand: '/hidden-skill',
            name: 'Hidden',
            description: 'Hidden',
            category: 'general',
            capability: 'agent.help',
            isHidden: true,
        );

        $result = $this->service->resolve($skill);

        self::assertSame(AgentSkillAvailability::HIDDEN, $result->status);
        self::assertFalse($result->usable);
    }
}
