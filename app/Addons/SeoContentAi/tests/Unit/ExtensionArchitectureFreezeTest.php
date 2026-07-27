<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Extension\Builtin\Wordpress\WordPressPublisher;
use App\Addons\SeoContentAi\Extension\ExtensionEvents;
use App\Addons\SeoContentAi\Services\ContentProject\Agent\ContentProjectAgentGateway;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Events\ContentProjectDomainEvents;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Handlers\ProcessScheduledProjectItemPublishHandler;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Publishing\PublisherResolver;
use App\Addons\SeoContentAi\Services\PromptRunnerService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Architecture Freeze v1.0 boundary test — pure source/reflection assertions.
 * Intentionally does NOT boot Laravel (no container, no DB) so it can run with
 * plain PHPUnit on the remote host — see `.cursor/rules/phpunit-remote.mdc`.
 *
 * @see \docs\ARCHITECTURE_FREEZE_V1.md
 * @see \docs\ARCHITECTURE_DECISIONS.md
 */
final class ExtensionArchitectureFreezeTest extends TestCase
{
    public function test_scheduled_publish_handler_resolves_publisher_via_resolver_not_builtin_wordpress(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(ProcessScheduledProjectItemPublishHandler::class))->getFileName(),
        );

        self::assertStringContainsString(PublisherResolver::class, $source);
        self::assertStringNotContainsString('WordPressPublisher', $source);
        self::assertStringNotContainsString('WordPressContentPublisher', $source);
    }

    public function test_application_publishing_folder_has_no_wordpress_content_publisher_file(): void
    {
        $dir = dirname(__DIR__, 2).'/Services/ContentProject/Application/Publishing';
        self::assertDirectoryExists($dir);

        self::assertFileDoesNotExist($dir.DIRECTORY_SEPARATOR.'WordPressContentPublisher.php');
    }

    public function test_wordpress_publisher_lives_under_extension_builtin_wordpress(): void
    {
        self::assertTrue(class_exists(WordPressPublisher::class));

        $path = (new ReflectionClass(WordPressPublisher::class))->getFileName();
        self::assertNotFalse($path);
        self::assertStringContainsString(
            'Extension'.DIRECTORY_SEPARATOR.'Builtin'.DIRECTORY_SEPARATOR.'Wordpress',
            (string) $path,
        );
    }

    public function test_prompt_runner_service_resolves_ai_provider_via_resolver(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(PromptRunnerService::class))->getFileName(),
        );

        self::assertStringContainsString('AiProviderResolver', $source);
    }

    public function test_agent_gateway_uses_canonical_capability_registry_not_raw_extension_registry_import(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(ContentProjectAgentGateway::class))->getFileName(),
        );

        self::assertStringContainsString('CanonicalCapabilityRegistry', $source);
        self::assertStringNotContainsString('use App\\Addons\\SeoContentAi\\Extension\\Registry\\ExtensionCapabilityRegistry;', $source);
    }

    public function test_domain_events_bridge_to_versioned_extension_event_envelope(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(ContentProjectDomainEvents::class))->getFileName(),
        );

        self::assertStringContainsString('ExtensionEventEnvelope', $source);
        self::assertStringContainsString('ExtensionEvents::', $source);

        self::assertStringEndsWith('.v1', ExtensionEvents::PROJECT_CREATED);
        self::assertStringEndsWith('.v1', ExtensionEvents::ITEMS_GENERATED);
        self::assertStringEndsWith('.v1', ExtensionEvents::PUBLISHED);
        self::assertStringEndsWith('.v1', ExtensionEvents::ARCHIVED);
    }

    public function test_application_handlers_do_not_import_builtin_wordpress_or_wordpress_content_publisher(): void
    {
        $dir = dirname(__DIR__, 2).'/Services/ContentProject/Application/Handlers';
        self::assertDirectoryExists($dir);

        $files = glob($dir.DIRECTORY_SEPARATOR.'*.php') ?: [];
        self::assertNotEmpty($files, 'Expected Application/Handlers directory to contain handler files.');

        foreach ($files as $file) {
            $source = (string) file_get_contents($file);

            self::assertDoesNotMatchRegularExpression(
                '/use\s+[^;]*Builtin\\\\Wordpress[^;]*;/',
                $source,
                basename($file).' must not import Extension\\Builtin\\Wordpress classes.',
            );
            self::assertStringNotContainsString(
                'WordPressContentPublisher',
                $source,
                basename($file).' must not reference WordPressContentPublisher.',
            );
        }
    }

    public function test_architecture_freeze_doc_soft_skips_if_missing(): void
    {
        $roots = [
            dirname(__DIR__, 5).DIRECTORY_SEPARATOR.'docs',
            getcwd().DIRECTORY_SEPARATOR.'docs',
        ];

        $names = [
            'ARCHITECTURE_FREEZE_V1.md',
            'ARCHITECTURE_DECISIONS.md',
            'BUILTIN_WORDPRESS_EXTENSION.md',
            'EXTENSION_SECURITY_BOUNDARY.md',
        ];

        $foundAny = false;
        foreach ($roots as $root) {
            foreach ($names as $name) {
                $path = $root.DIRECTORY_SEPARATOR.$name;
                if (is_file($path)) {
                    $foundAny = true;
                    $body = (string) file_get_contents($path);
                    self::assertNotSame('', trim($body));
                }
            }
        }

        if (! $foundAny) {
            self::markTestSkipped('Architecture Freeze docs not present on this host');
        }
    }

    public function test_seo_architecture_config_exists_and_is_frozen_at_sdk_version_one(): void
    {
        $paths = [
            dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'seo_architecture.php',
            getcwd().DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Addons'.DIRECTORY_SEPARATOR.'SeoContentAi'.DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'seo_architecture.php',
        ];

        $configPath = null;
        foreach ($paths as $path) {
            if (is_file($path)) {
                $configPath = $path;
                break;
            }
        }

        if ($configPath === null) {
            self::markTestSkipped('config/seo_architecture.php not present on this host');
        }

        $config = require $configPath;

        self::assertIsArray($config);
        self::assertSame(1, $config['sdk_version'] ?? null);
        self::assertSame('content_project.', $config['core_capabilities_protected_prefix'] ?? null);
        self::assertSame('/^[a-z0-9][a-z0-9._-]*$/', $config['extension_id_pattern'] ?? null);
        self::assertIsArray($config['event_versions'] ?? null);
        self::assertIsArray($config['forbidden_dependency_rules'] ?? null);
        self::assertSame(['cp_', 'cpi_'], $config['public_reference_prefixes'] ?? null);
    }
}
