<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Extension;

use App\Addons\SeoContentAi\Extension\Contracts\AiProviderDriver;
use App\Addons\SeoContentAi\Extension\Contracts\MediaProcessorDriver;
use App\Addons\SeoContentAi\Extension\Contracts\PipelineStepDriver;
use App\Addons\SeoContentAi\Extension\Contracts\PublisherDriver;
use App\Addons\SeoContentAi\Extension\Contracts\SeoProviderDriver;
use App\Addons\SeoContentAi\Extension\Registry\ContentPlatformRegistry;
use App\Addons\SeoContentAi\Extension\Registry\ExtensionRegistry;

final class ExtensionHealthService
{
    public function __construct(
        private readonly ContentPlatformRegistry $platform,
        private readonly ExtensionRegistry $extensionRegistry,
        private readonly ExtensionStateStore $stateStore,
    ) {}

    /**
     * @return list<array{extension_id: string, ok: bool, message: string, drivers: list<array<string, mixed>>}>
     */
    public function runAll(): array
    {
        $results = [];

        foreach ($this->extensionRegistry->installed() as $definition) {
            $id = $definition->manifest->id;

            if (! $this->stateStore->isEnabled($id)) {
                continue;
            }

            $driverResults = $this->collectDriverHealth($definition);
            $ok = $driverResults === [] || array_reduce(
                $driverResults,
                static fn (bool $carry, array $row): bool => $carry && (bool) ($row['ok'] ?? false),
                true,
            );
            $message = $ok ? 'All drivers healthy.' : 'One or more drivers reported errors.';

            $snapshot = [
                'status' => $ok ? 'healthy' : 'error',
                'ok' => $ok,
                'message' => $message,
                'drivers' => $driverResults,
                'checked_at' => now()->toDateTimeString(),
            ];

            $this->stateStore->setHealth($id, $snapshot);
            $definition->status = $ok ? 'healthy' : 'error';
            $definition->health = $snapshot;

            $results[] = [
                'extension_id' => $id,
                'ok' => $ok,
                'message' => $message,
                'drivers' => $driverResults,
            ];
        }

        return $results;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function collectDriverHealth(ExtensionDefinition $definition): array
    {
        $results = [];
        $extensionId = $definition->manifest->id;

        foreach ($definition->manifest->providers as $providerType) {
            match ($providerType) {
                'publisher' => $this->appendDriverHealth(
                    $results,
                    $this->platform->publishers()->get($extensionId),
                ),
                'ai' => $this->appendDriverHealth(
                    $results,
                    $this->platform->aiProviders()->get($extensionId),
                ),
                'seo' => $this->appendDriverHealth(
                    $results,
                    $this->platform->seoProviders()->get($extensionId),
                ),
                'pipeline' => $this->appendDriverHealth(
                    $results,
                    $this->platform->pipelines()->get($extensionId),
                ),
                'media' => $this->appendDriverHealth(
                    $results,
                    $this->platform->mediaProcessors()->get($extensionId),
                ),
                default => null,
            };
        }

        return $results;
    }

    /**
     * @param  list<array<string, mixed>>  $results
     */
    private function appendDriverHealth(array &$results, PublisherDriver|AiProviderDriver|SeoProviderDriver|PipelineStepDriver|MediaProcessorDriver|null $driver): void
    {
        if ($driver === null) {
            $results[] = [
                'ok' => false,
                'message' => 'Driver not registered.',
            ];

            return;
        }

        $health = $driver->health();
        $results[] = [
            'id' => method_exists($driver, 'id') ? $driver->id() : 'unknown',
            'ok' => (bool) ($health['ok'] ?? false),
            'message' => (string) ($health['message'] ?? ''),
        ];
    }
}
