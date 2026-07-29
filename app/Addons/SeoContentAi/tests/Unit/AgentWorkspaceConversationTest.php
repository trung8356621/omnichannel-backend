<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Services\AgentWorkspace\AgentConversationService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class AgentWorkspaceConversationTest extends TestCase
{
    public function test_migration_defines_agent_workspace_tables(): void
    {
        $migrationsDir = dirname(__DIR__, 2).'/database/migrations';
        $matches = glob($migrationsDir.'/*create_seo_agent_workspace_tables.php') ?: [];
        self::assertNotEmpty($matches, 'Missing migration *create_seo_agent_workspace_tables.php under '.$migrationsDir);

        $migrationPath = $matches[0];
        self::assertFileExists($migrationPath);

        $source = (string) file_get_contents($migrationPath);

        self::assertStringContainsString('seo_agent_conversations', $source);
        self::assertStringContainsString('seo_agent_messages', $source);
        self::assertStringContainsString('seo_agent_executions', $source);
        self::assertStringContainsString("'role'", $source);
        self::assertStringContainsString("'message_type'", $source);
    }

    public function test_delete_empty_does_not_delete_business_operations(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(AgentConversationService::class))->getFileName(),
        );

        self::assertStringContainsString('function deleteEmpty', $source);
        self::assertStringNotContainsString('SeoProjectOperation', $source);
        self::assertStringNotContainsString('SeoProject::', $source);
        self::assertStringContainsString('SeoAgentExecution::query()', $source);
        self::assertStringContainsString('$conversation->delete()', $source);
    }

    public function test_append_message_updates_last_message_at(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(AgentConversationService::class))->getFileName(),
        );

        self::assertStringContainsString('function appendMessage', $source);
        self::assertStringContainsString('$conversation->last_message_at = $message->created_at', $source);
        self::assertStringContainsString('$conversation->save()', $source);
    }
}
