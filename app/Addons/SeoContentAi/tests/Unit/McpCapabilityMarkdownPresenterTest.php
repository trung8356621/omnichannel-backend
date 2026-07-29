<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Extension\ExtensionStateStore;
use App\Addons\SeoContentAi\Extension\Registry\ExtensionCapabilityRegistry;
use App\Addons\SeoContentAi\Filament\Resources\DomainResource;
use App\Addons\SeoContentAi\Filament\Resources\DomainResource\Pages\GeneralDomain;
use App\Addons\SeoContentAi\Filament\Pages\AgentWorkspacePage;
use App\Addons\SeoContentAi\Services\ContentProject\Agent\Mcp\ContentProjectMcpToolCatalog;
use App\Addons\SeoContentAi\Services\ContentProject\Agent\Mcp\McpCapabilityMarkdownPresenter;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Capabilities\CanonicalCapabilityRegistry;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Capabilities\ContentProjectCapabilityRegistry;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class McpCapabilityMarkdownPresenterTest extends TestCase
{
    private function addonRoot(): string
    {
        return dirname((new ReflectionClass(McpCapabilityMarkdownPresenter::class))->getFileName(), 5);
    }

    private function addonView(string $relativePath): string
    {
        return $this->addonRoot().'/resources/views/'.$relativePath;
    }

    public function test_present_reads_from_registry_not_hardcoded_site_list(): void
    {
        $presenterSource = (string) file_get_contents(
            (new ReflectionClass(McpCapabilityMarkdownPresenter::class))->getFileName(),
        );
        $generalSource = (string) file_get_contents(
            (new ReflectionClass(GeneralDomain::class))->getFileName(),
        );
        $domainGeneralView = (string) file_get_contents($this->addonView(
            'filament/resources/domain-resource/pages/general-domain.blade.php',
        ));

        self::assertStringContainsString('registry->all()', $presenterSource);
        self::assertStringNotContainsString("'site.discover', 'site.sync'", $presenterSource);
        self::assertStringContainsString('McpCapabilityMarkdownPresenter', $generalSource);
        self::assertStringContainsString('mcpCapabilityDoc', $generalSource);
        self::assertStringContainsString('MCP Markdown', $domainGeneralView);
        self::assertStringNotContainsString('refreshMcpCapabilityDoc', $domainGeneralView);
    }

    public function test_registered_capabilities_appear_in_markdown(): void
    {
        $presenter = $this->presenter();
        $doc = $presenter->present(includeInternal: true, filter: McpCapabilityMarkdownPresenter::FILTER_SITE_SYNC);
        $names = array_map(
            static fn (array $row): string => (string) $row['name'],
            array_merge($doc['items'], $doc['internal_items']),
        );

        self::assertContains('site.discover', $names);
        self::assertContains('site.sync', $names);
        self::assertStringContainsString('## site.discover', $doc['markdown']);
        self::assertStringContainsString('## site.sync', $doc['markdown']);
        self::assertStringContainsString('**Loại:** Read', $doc['markdown']);
        self::assertStringContainsString('Phát hiện thông tin website', $doc['markdown']);
    }

    public function test_content_project_capabilities_from_registry_only(): void
    {
        $doc = $this->presenter()->present(
            includeInternal: false,
            filter: McpCapabilityMarkdownPresenter::FILTER_CONTENT_PROJECT,
        );
        $names = array_column($doc['items'], 'name');

        self::assertContains('content_project.create', $names);
        self::assertNotContains('content_project.list', $names);
        self::assertNotContains('content_project.get', $names);
    }

    public function test_disabled_capability_shows_disabled_status(): void
    {
        $doc = $this->presenter()->presentFromDefinitions(
            [[
                'name' => 'demo.disabled',
                'description' => 'Disabled demo',
                'risk_level' => 'read',
                'read_only' => true,
                'enabled' => false,
                'scopes' => ['demo:read'],
                'input_summary' => ['site_id'],
                'output_summary' => ['ok'],
                'examples' => ['Demo'],
                'confirmation_requirement' => false,
            ]],
            mcpToolNames: [],
            includeInternal: false,
            filter: McpCapabilityMarkdownPresenter::FILTER_ALL,
        );

        self::assertSame(['disabled'], $doc['items'][0]['status']);
        self::assertStringContainsString('disabled', $doc['markdown']);
    }

    public function test_internal_hidden_from_regular_user_visible_to_manager(): void
    {
        $caps = [[
            'name' => 'demo.public',
            'description' => 'Public',
            'risk_level' => 'read',
            'read_only' => true,
            'internal' => false,
            'enabled' => true,
            'scopes' => ['demo:read'],
            'confirmation_requirement' => false,
        ], [
            'name' => 'demo.internal',
            'description' => 'Internal only',
            'risk_level' => 'write',
            'read_only' => false,
            'internal' => true,
            'visibility' => 'internal',
            'enabled' => true,
            'scopes' => ['demo:admin'],
            'confirmation_requirement' => true,
            'confirmation_note' => 'Có',
        ]];

        $regular = $this->presenter()->presentFromDefinitions($caps, [], includeInternal: false);
        $manager = $this->presenter()->presentFromDefinitions($caps, [], includeInternal: true);

        self::assertCount(1, $regular['items']);
        self::assertSame('demo.public', $regular['items'][0]['name']);
        self::assertSame([], $regular['internal_items']);
        self::assertStringNotContainsString('demo.internal', $regular['markdown']);

        self::assertCount(1, $manager['items']);
        self::assertCount(1, $manager['internal_items']);
        self::assertSame('demo.internal', $manager['internal_items'][0]['name']);
        self::assertStringContainsString('## Internal capabilities', $manager['markdown']);
        self::assertStringContainsString('## demo.internal', $manager['markdown']);
    }

    public function test_read_write_confirmation_metadata(): void
    {
        $doc = $this->presenter()->presentFromDefinitions(
            [[
                'name' => 'demo.write',
                'description' => 'Write with confirm',
                'risk_level' => 'write',
                'read_only' => false,
                'enabled' => true,
                'scopes' => ['demo:write'],
                'confirmation_requirement' => true,
                'confirmation_modes' => ['confirm'],
                'confirmation_note' => 'Có',
                'input_summary' => ['project_ref'],
                'output_summary' => ['operation_id'],
                'examples' => ['Chạy demo'],
                'agent_exposed' => true,
                'mcp_exposed' => true,
            ], [
                'name' => 'demo.read',
                'description' => 'Read only',
                'risk_level' => 'read',
                'read_only' => true,
                'enabled' => true,
                'scopes' => ['demo:read'],
                'confirmation_requirement' => false,
                'input_summary' => ['project_ref'],
                'agent_exposed' => true,
                'mcp_exposed' => false,
            ]],
            mcpToolNames: ['demo.write' => true],
            includeInternal: false,
        );

        $byName = [];
        foreach ($doc['items'] as $item) {
            $byName[$item['name']] = $item;
        }

        self::assertSame('Write', $byName['demo.write']['type']);
        self::assertTrue($byName['demo.write']['confirmation']);
        self::assertSame('Có', $byName['demo.write']['confirmation_policy']);
        self::assertContains('exposed-to-mcp', $byName['demo.write']['status']);
        self::assertContains('exposed-to-agent', $byName['demo.write']['status']);

        self::assertSame('Read', $byName['demo.read']['type']);
        self::assertFalse($byName['demo.read']['confirmation']);
        self::assertNotContains('exposed-to-mcp', $byName['demo.read']['status']);
    }

    public function test_copy_markdown_contains_registered_names_and_omits_secrets(): void
    {
        $doc = $this->presenter()->present(includeInternal: false, filter: McpCapabilityMarkdownPresenter::FILTER_ALL);

        self::assertStringContainsString('# MCP Capabilities', $doc['markdown']);
        self::assertStringContainsString('content_project.create', $doc['markdown']);
        self::assertStringNotContainsString("'handler'", $doc['markdown']);
        self::assertStringNotContainsString('api_key', $doc['markdown']);
        self::assertStringNotContainsString('password', $doc['markdown']);

        foreach ($doc['items'] as $item) {
            self::assertArrayNotHasKey('handler', $item);
            self::assertArrayNotHasKey('input_schema', $item);
        }
    }

    public function test_new_capability_appears_without_page_change(): void
    {
        $base = $this->presenter()->presentFromDefinitions([], [], includeInternal: false);
        self::assertSame(0, $base['count']);

        $withNew = $this->presenter()->presentFromDefinitions(
            [[
                'name' => 'future.brand_new',
                'description' => 'Appears automatically',
                'risk_level' => 'read',
                'read_only' => true,
                'enabled' => true,
                'scopes' => ['future:read'],
                'confirmation_requirement' => false,
                'examples' => ['New cap'],
            ]],
            mcpToolNames: ['future.brand_new' => true],
            includeInternal: false,
        );

        self::assertSame(1, $withNew['count']);
        self::assertSame('future.brand_new', $withNew['items'][0]['name']);
        self::assertStringContainsString('## future.brand_new', $withNew['markdown']);
    }

    public function test_removed_capability_disappears(): void
    {
        $caps = [[
            'name' => 'temp.one',
            'description' => 'Temp',
            'risk_level' => 'read',
            'read_only' => true,
            'enabled' => true,
            'confirmation_requirement' => false,
        ]];

        $before = $this->presenter()->presentFromDefinitions($caps, [], includeInternal: false);
        $after = $this->presenter()->presentFromDefinitions([], [], includeInternal: false);

        self::assertStringContainsString('temp.one', $before['markdown']);
        self::assertStringNotContainsString('temp.one', $after['markdown']);
    }

    public function test_site_sync_presentation_metadata_on_registry(): void
    {
        $cap = (new ContentProjectCapabilityRegistry)->get('site.discover');
        self::assertNotNull($cap);
        self::assertTrue((bool) ($cap['read_only'] ?? false));
        self::assertSame(['site:read'], $cap['scopes'] ?? null);
        self::assertContains('site profile', $cap['output_summary'] ?? []);

        $sync = (new ContentProjectCapabilityRegistry)->get('site.sync');
        self::assertNotNull($sync);
        self::assertFalse((bool) ($sync['confirmation_requirement'] ?? true));
        self::assertContains('force_full', $sync['confirmation_modes'] ?? []);
        self::assertSame('Có khi dùng `force_full`', $sync['confirmation_note'] ?? null);
    }

    public function test_agent_general_block_guards_and_presenter_wiring(): void
    {
        $agentPageSource = (string) file_get_contents(
            (new ReflectionClass(AgentWorkspacePage::class))->getFileName(),
        );
        $generalSource = (string) file_get_contents(
            (new ReflectionClass(GeneralDomain::class))->getFileName(),
        );
        $resourceSource = (string) file_get_contents(
            (new ReflectionClass(DomainResource::class))->getFileName(),
        );

        self::assertStringNotContainsString('McpCapabilityMarkdownPresenter', $agentPageSource);
        self::assertStringNotContainsString('mcpCapabilityDoc', $agentPageSource);

        self::assertStringNotContainsString('viewMcpCapabilitiesAction', $generalSource);
        self::assertStringNotContainsString('mcp-capabilities', $resourceSource);
        self::assertStringContainsString('McpCapabilityMarkdownPresenter', $generalSource);
        self::assertStringContainsString('mcpCapabilityDoc', $generalSource);
        self::assertStringContainsString('loadMcpCapabilityDoc', $generalSource);
        self::assertFileDoesNotExist($this->addonView(
            'filament/resources/domain-resource/pages/view-domain-mcp-capabilities.blade.php',
        ));

        $domainGeneralView = (string) file_get_contents($this->addonView(
            'filament/resources/domain-resource/pages/general-domain.blade.php',
        ));
        self::assertStringContainsString('MCP Markdown', $domainGeneralView);
        self::assertStringContainsString('mcpCapabilityDoc', $domainGeneralView);

        self::assertFileDoesNotExist($this->addonView(
            'filament/pages/partials/agent-workspace/general-panel.blade.php',
        ));
    }

    private function presenter(): McpCapabilityMarkdownPresenter
    {
        $registry = new CanonicalCapabilityRegistry(
            new ContentProjectCapabilityRegistry,
            new ExtensionCapabilityRegistry,
            new ExtensionStateStore,
        );

        return new McpCapabilityMarkdownPresenter(
            $registry,
            new ContentProjectMcpToolCatalog($registry),
        );
    }
}
