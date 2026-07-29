<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Extension\ExtensionStateStore;
use App\Addons\SeoContentAi\Extension\Registry\ExtensionCapabilityRegistry;
use App\Addons\SeoContentAi\Services\AgentWorkspace\AgentSkillAvailabilityService;
use App\Addons\SeoContentAi\Services\AgentWorkspace\AgentSkillRegistry;
use App\Addons\SeoContentAi\Services\AgentWorkspace\Dtos\AgentSkillAvailability;
use App\Addons\SeoContentAi\Services\AgentWorkspace\Dtos\AgentWorkspaceContext;
use App\Addons\SeoContentAi\Services\AgentWorkspace\Planning\Services\AgentSkillCatalogPresenter;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Capabilities\CanonicalCapabilityRegistry;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Capabilities\ContentProjectCapabilityRegistry;
use PHPUnit\Framework\TestCase;

final class AgentSkillCatalogPresenterTest extends TestCase
{
    public function test_hides_internal_and_limits_count(): void
    {
        $skills = new AgentSkillRegistry([
            [
                'key' => 'content_project.create',
                'slash_command' => '/create-project',
                'name' => 'Tạo Content Project',
                'description' => 'Create project',
                'category' => 'content_project',
                'capability' => 'content_project.create',
                'is_featured' => true,
                'availability_policy' => ['status_override' => AgentSkillAvailability::AVAILABLE],
                'form_schema' => [
                    ['key' => 'project_name', 'label' => 'Name', 'type' => 'text', 'required' => true],
                    ['key' => 'month', 'label' => 'Month', 'type' => 'month', 'required' => true],
                ],
            ],
            [
                'key' => 'hidden.skill',
                'slash_command' => '/hidden',
                'name' => 'Hidden',
                'description' => 'Hidden',
                'category' => 'internal',
                'capability' => 'agent.help',
                'is_hidden' => true,
                'availability_policy' => ['status_override' => AgentSkillAvailability::AVAILABLE],
            ],
        ]);

        $availability = new AgentSkillAvailabilityService(new CanonicalCapabilityRegistry(
            new ContentProjectCapabilityRegistry,
            new ExtensionCapabilityRegistry,
            new ExtensionStateStore,
        ));

        $presenter = new AgentSkillCatalogPresenter($skills, $availability, maxSkills: 1);
        $context = new AgentWorkspaceContext(
            tenantRef: 't',
            siteRef: 's',
            tenantId: 1,
            siteId: 1,
            connectionId: null,
            siteName: 'S',
            actorRef: 'u',
            actorUserId: 1,
            role: 'manager',
        );

        $rows = $presenter->present($context, 'tạo project nội dung');

        self::assertCount(1, $rows);
        self::assertSame('content_project.create', $rows[0]['key']);
        self::assertArrayHasKey('required_inputs', $rows[0]);
        self::assertArrayNotHasKey('capability', $rows[0]);
        self::assertDoesNotMatchRegularExpression('/hidden/', implode(',', array_column($rows, 'key')));
    }
}
