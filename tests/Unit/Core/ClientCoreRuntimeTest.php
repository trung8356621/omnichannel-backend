<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Addon\AddonDependencyValidator;
use App\Core\Addon\AddonDiscovery;
use App\Core\Addon\AddonManifest;
use App\Core\Addon\AddonRegistry;
use App\Core\Api\ApiEnvelope;
use App\Core\Capability\CapabilityRegistry;
use App\Core\Command\CommandBus;
use App\Core\Command\CommandResult;
use App\Core\Command\Contracts\Command;
use App\Core\Command\Contracts\CommandHandler;
use App\Core\Event\Contracts\DomainEvent;
use App\Core\Event\Contracts\EventListener;
use App\Core\Event\EventBus;
use App\Core\Operations\CorrelationId;
use PHPUnit\Framework\TestCase;

final class ClientCoreRuntimeTest extends TestCase
{
    protected function tearDown(): void
    {
        CorrelationId::clear();
        parent::tearDown();
    }

    public function test_capability_registry_optional_missing_does_not_throw(): void
    {
        $caps = new CapabilityRegistry();
        $this->assertNull($caps->optional('seo.audit'));
        $this->assertFalse($caps->has('seo.audit'));
    }

    public function test_search_foundation_and_seo_share_keyword_capability_contract(): void
    {
        $caps = new CapabilityRegistry();
        $caps->register('search.keyword', (object) ['id' => 'search.keyword'], 'search-foundation');
        $caps->register('seo.audit', (object) ['id' => 'seo.audit'], 'seo');
        $caps->register('search.intelligence', (object) ['id' => 'search.intelligence'], 'search-intelligence');

        $this->assertTrue($caps->has('search.keyword'));
        $this->assertSame('search-foundation', $caps->ownerOf('search.keyword'));
        $this->assertTrue($caps->has('seo.audit'));
        $this->assertTrue($caps->has('search.intelligence'));
    }

    public function test_dependency_validator_fails_when_required_capability_absent(): void
    {
        $caps = new CapabilityRegistry();
        $validator = new AddonDependencyValidator($caps);

        $seo = AddonManifest::fromArray([
            'slug' => 'seo',
            'name' => 'SEO',
            'provider' => 'Omnichannel\\Addons\\Seo\\SeoServiceProvider',
            'requires' => ['search.keyword'],
            'provides' => ['seo.audit'],
        ], '/tmp/seo');

        $violations = $validator->validate([$seo]);
        $this->assertNotEmpty($violations);
    }

    public function test_dependency_validator_passes_when_peer_provides_required(): void
    {
        $caps = new CapabilityRegistry();
        $validator = new AddonDependencyValidator($caps);

        $sf = AddonManifest::fromArray([
            'slug' => 'search-foundation',
            'name' => 'SF',
            'provider' => 'Omnichannel\\Addons\\SearchFoundation\\SearchFoundationServiceProvider',
            'provides' => ['search.keyword'],
        ], '/tmp/sf');

        $seo = AddonManifest::fromArray([
            'slug' => 'seo',
            'name' => 'SEO',
            'provider' => 'Omnichannel\\Addons\\Seo\\SeoServiceProvider',
            'requires' => ['search.keyword'],
            'provides' => ['seo.audit'],
        ], '/tmp/seo');

        $this->assertSame([], $validator->validate([$sf, $seo]));
    }

    public function test_command_bus_dispatches_and_returns_result(): void
    {
        $bus = new CommandBus();
        $bus->register(DummyCommand::class, new class implements CommandHandler
        {
            public function handle(Command $command): mixed
            {
                return ['echo' => $command->name()];
            }
        });

        $result = $bus->dispatch(new DummyCommand());
        $this->assertInstanceOf(CommandResult::class, $result);
        $this->assertTrue($result->ok);
        $this->assertSame('dummy', $result->payload['echo']);
    }

    public function test_event_bus_delivers_to_listeners(): void
    {
        $bus = new EventBus();
        $seen = [];
        $bus->listen('demo.event', new class($seen) implements EventListener
        {
            /** @param list<string> $seen */
            public function __construct(private array &$seen) {}

            public function handle(DomainEvent $event): void
            {
                $this->seen[] = $event->name();
            }
        });

        $bus->dispatch(new class implements DomainEvent
        {
            public function name(): string
            {
                return 'demo.event';
            }

            public function payload(): array
            {
                return [];
            }
        });

        $this->assertSame(['demo.event'], $seen);
    }

    public function test_api_envelope_success_shape(): void
    {
        $payload = ApiEnvelope::success(['x' => 1], ['request_id' => 'abc']);
        $this->assertTrue($payload['ok']);
        $this->assertSame(['x' => 1], $payload['data']);
        $this->assertSame('v1', $payload['meta']['api_version']);
    }

    public function test_addon_discovery_finds_peer_addons_without_legacy(): void
    {
        $tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'omi_addon_disc_'.bin2hex(random_bytes(4));
        mkdir($tmp.'/search-foundation', 0777, true);
        file_put_contents($tmp.'/search-foundation/addon.json', json_encode([
            'slug' => 'search-foundation',
            'name' => 'Search Foundation',
            'provider' => 'Omnichannel\\Addons\\SearchFoundation\\SearchFoundationServiceProvider',
            'provides' => ['search.keyword'],
        ]));

        $discovery = new AddonDiscovery();
        $manifests = $discovery->discover([$tmp], []);
        $this->assertCount(1, $manifests);
        $this->assertSame('search-foundation', $manifests[0]->slug);

        $registry = new AddonRegistry();
        $registry->replaceAll($manifests);
        $this->assertTrue($registry->has('search-foundation'));
        $this->assertFalse($registry->has('seo-content-ai'));

        $this->removeTree($tmp);
    }

    public function test_boot_matrix_optional_addons_absent_ok(): void
    {
        // Matrix: Core + search-foundation only — no publishing/wordpress/seo/intelligence.
        $caps = new CapabilityRegistry();
        $caps->register('search.keyword', (object) ['owner' => 'search-foundation'], 'search-foundation');

        $this->assertFalse($caps->has('publishing.queue'));
        $this->assertFalse($caps->has('wordpress.bridge'));
        $this->assertFalse($caps->has('seo.audit'));
        $this->assertFalse($caps->has('search.intelligence'));
        $this->assertFalse($caps->has('ai.prompt'));
        $this->assertFalse($caps->has('social.publish'));
        $this->assertFalse($caps->has('commerce.product'));
        $this->assertTrue($caps->has('search.keyword'));
    }

    public function test_seo_without_intelligence_and_intelligence_without_seo(): void
    {
        $seoOnly = new CapabilityRegistry();
        $seoOnly->register('search.keyword', (object) [], 'search-foundation');
        $seoOnly->register('seo.audit', (object) [], 'seo');
        $this->assertTrue($seoOnly->has('seo.audit'));
        $this->assertFalse($seoOnly->has('search.intelligence'));

        $intelOnly = new CapabilityRegistry();
        $intelOnly->register('search.keyword', (object) [], 'search-foundation');
        $intelOnly->register('search.intelligence', (object) [], 'search-intelligence');
        $this->assertTrue($intelOnly->has('search.intelligence'));
        $this->assertFalse($intelOnly->has('seo.audit'));
    }

    private function removeTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.DIRECTORY_SEPARATOR.$item;
            if (is_dir($path)) {
                $this->removeTree($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}

final class DummyCommand implements Command
{
    public function name(): string
    {
        return 'dummy';
    }
}
