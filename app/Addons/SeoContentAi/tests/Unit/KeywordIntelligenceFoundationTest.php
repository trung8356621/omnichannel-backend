<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Enums\KeywordIntelligence\KeywordSearchIntent;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Contracts\ContentProjectCommandHandler;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\KeywordIntelligencePublicRef;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\KeywordIntentClassifier;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\KeywordNormalizationService;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\KeywordScoringService;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\KeywordToContentProjectConverter;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class KeywordIntelligenceFoundationTest extends TestCase
{
    public function test_normalization_trims_punctuation_keeps_vietnamese(): void
    {
        $svc = new KeywordNormalizationService;
        self::assertSame('dịch vụ seo tổng thể', $svc->normalize('  - Dịch vụ SEO tổng thể:  '));
        self::assertSame('Dịch vụ SEO tổng thể', $svc->displayKeyword('  - Dịch vụ SEO tổng thể:  '));
        self::assertStringContainsString('ệ', $svc->displayKeyword('dịch vụ SEO tổng thể'));
    }

    public function test_near_duplicate_does_not_merge_different_entities(): void
    {
        $svc = new KeywordNormalizationService;
        self::assertFalse($svc->isNearDuplicate(
            $svc->normalize('seo là gì'),
            $svc->normalize('dịch vụ seo'),
        ));
    }

    public function test_intent_classifier_local_commercial(): void
    {
        $classifier = new KeywordIntentClassifier;
        $result = $classifier->classify('dịch vụ seo tphcm', 'dịch vụ seo tphcm');
        self::assertContains($result['primary'], [
            KeywordSearchIntent::Local,
            KeywordSearchIntent::Mixed,
            KeywordSearchIntent::Commercial,
        ]);
        self::assertGreaterThan(0.5, $result['confidence']);
        self::assertSame('rule', $result['source']);
    }

    public function test_scoring_returns_factors_without_external_metrics(): void
    {
        $scoring = new KeywordScoringService;
        $result = $scoring->score([
            'relevance' => 80,
            'business_value' => 70,
            'intent' => KeywordSearchIntent::Commercial->value,
            'has_existing_coverage' => false,
        ]);

        self::assertArrayHasKey('priority_score', $result);
        self::assertArrayHasKey('score_factors', $result);
        self::assertNotEmpty($result['score_factors']);
        self::assertLessThan(0.8, $result['confidence']);
    }

    public function test_public_ref_roundtrip_rejects_numeric(): void
    {
        $ref = KeywordIntelligencePublicRef::workspace(42);
        self::assertSame(42, KeywordIntelligencePublicRef::decodeWorkspace($ref));
        self::assertSame(42, KeywordIntelligencePublicRef::resolveWorkspaceIdStrict($ref));

        $this->expectException(\InvalidArgumentException::class);
        KeywordIntelligencePublicRef::resolveWorkspaceIdStrict('42');
    }

    public function test_converter_uses_command_bus_create_project(): void
    {
        $path = dirname(__DIR__, 2).'/Services/KeywordIntelligence/KeywordToContentProjectConverter.php';
        if (! is_file($path)) {
            self::markTestSkipped('Converter missing');
        }

        $source = (string) file_get_contents($path);
        self::assertStringContainsString('CreateContentProjectCommand', $source);
        self::assertStringContainsString('ContentProjectCommandBus', $source);
        self::assertStringNotContainsString('gallery_description', $source);
    }

    public function test_architecture_handlers_folder_avoids_wordpress_builtin_if_present(): void
    {
        $dir = dirname(__DIR__, 2).'/Services/KeywordIntelligence/Application/Handlers';
        if (! is_dir($dir)) {
            self::markTestSkipped('Handlers not present yet');
        }

        foreach (glob($dir.'/*.php') ?: [] as $file) {
            $source = (string) file_get_contents($file);
            self::assertStringNotContainsString('Extension\\Builtin\\Wordpress', $source, basename($file));
            self::assertStringNotContainsString('WordPressContentPublisher', $source, basename($file));
        }
    }

    /**
     * @return array<string, class-string<ContentProjectCommand>>
     */
    public static function commandClassesProvider(): array
    {
        $ns = 'App\\Addons\\SeoContentAi\\Services\\KeywordIntelligence\\Application\\Commands\\';

        return [
            'create_workspace' => [$ns.'CreateKeywordWorkspaceCommand', 'keyword_intelligence.create_workspace'],
            'import_keywords' => [$ns.'ImportKeywordsCommand', 'keyword_intelligence.import_keywords'],
            'analyze_workspace' => [$ns.'AnalyzeKeywordWorkspaceCommand', 'keyword_intelligence.analyze_workspace'],
            'approve_keywords' => [$ns.'ApproveKeywordsCommand', 'keyword_intelligence.approve_keywords'],
            'approve_clusters' => [$ns.'ApproveKeywordClustersCommand', 'keyword_intelligence.approve_clusters'],
            'build_topical_map' => [$ns.'BuildTopicalMapCommand', 'keyword_intelligence.build_topical_map'],
            'preview_convert' => [$ns.'PreviewContentProjectFromClustersCommand', 'keyword_intelligence.preview_convert'],
            'convert_to_content_project' => [$ns.'CreateContentProjectFromKeywordClustersCommand', 'keyword_intelligence.convert_to_content_project'],
            'archive_workspace' => [$ns.'ArchiveKeywordWorkspaceCommand', 'keyword_intelligence.archive_workspace'],
        ];
    }

    public function test_all_keyword_intelligence_commands_implement_content_project_command_contract(): void
    {
        foreach (self::commandClassesProvider() as $name => [$class, $expectedName]) {
            self::assertTrue(class_exists($class), "{$name}: {$class} must exist.");
            self::assertContains(ContentProjectCommand::class, class_implements($class) ?: [], "{$name} must implement ContentProjectCommand.");

            $ref = new ReflectionClass($class);
            $ctor = $ref->getConstructor();
            self::assertNotNull($ctor, "{$name} must declare a constructor.");

            // name() is a pure capability string — instantiate via reflection is unnecessary;
            // assert the method exists and returns the expected literal from source.
            $source = (string) file_get_contents((string) $ref->getFileName());
            self::assertStringContainsString("'{$expectedName}'", $source, "{$name} must declare name() => '{$expectedName}'.");
        }
    }

    public function test_all_keyword_intelligence_handlers_implement_command_handler_contract(): void
    {
        $dir = dirname(__DIR__, 2).'/Services/KeywordIntelligence/Application/Handlers';
        self::assertDirectoryExists($dir);

        $files = glob($dir.'/*.php') ?: [];
        self::assertNotEmpty($files, 'Expected Keyword Intelligence Handlers directory to contain handler files.');

        foreach ($files as $file) {
            $basename = basename($file, '.php');
            if ($basename === 'AbstractKeywordIntelligenceHandler') {
                continue;
            }

            $class = 'App\\Addons\\SeoContentAi\\Services\\KeywordIntelligence\\Application\\Handlers\\'.$basename;
            self::assertTrue(class_exists($class), "{$basename} must be autoloadable.");
            self::assertContains(
                ContentProjectCommandHandler::class,
                class_implements($class) ?: [],
                "{$basename} must implement ContentProjectCommandHandler.",
            );
        }
    }

    public function test_converter_dispatches_through_content_project_command_bus_with_create_content_project_command(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(KeywordToContentProjectConverter::class))->getFileName(),
        );

        self::assertStringContainsString('CreateContentProjectCommand', $source);
        self::assertStringContainsString('ContentProjectCommandBus', $source);
        self::assertStringContainsString('$bus->dispatch(', $source);
        // No gallery_description leakage into keyword-driven task rows.
        self::assertStringNotContainsString('gallery_description', $source);
    }

    public function test_registrar_wires_every_keyword_intelligence_command_to_a_handler(): void
    {
        $registrarPath = dirname(__DIR__, 2).'/Services/ContentProject/Application/ContentProjectCommandBusRegistrar.php';
        self::assertFileExists($registrarPath);

        $source = (string) file_get_contents($registrarPath);

        foreach (self::commandClassesProvider() as $name => [$class]) {
            $short = substr((string) strrchr($class, '\\'), 1);
            self::assertStringContainsString($short.'::class', $source, "Registrar must map {$name} ({$short}).");
        }
    }

    public function test_keyword_intelligence_capabilities_are_registered_in_capability_registry(): void
    {
        $registryPath = dirname(__DIR__, 2).'/Services/ContentProject/Application/Capabilities/ContentProjectCapabilityRegistry.php';
        self::assertFileExists($registryPath);

        $source = (string) file_get_contents($registryPath);

        foreach (self::commandClassesProvider() as [, $expectedName]) {
            self::assertStringContainsString("'{$expectedName}'", $source, "Capability registry must expose {$expectedName}.");
        }
    }
}
