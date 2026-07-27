<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Extension;

use App\Addons\SeoContentAi\Extension\Registry\AiProviderRegistry;
use App\Addons\SeoContentAi\Extension\Registry\ExtensionCapabilityRegistry;
use App\Addons\SeoContentAi\Extension\Registry\ExtensionRegistry;
use App\Addons\SeoContentAi\Extension\Registry\MediaProcessorRegistry;
use App\Addons\SeoContentAi\Extension\Registry\PipelineRegistry;
use App\Addons\SeoContentAi\Extension\Registry\PromptHookExtensionRegistry;
use App\Addons\SeoContentAi\Extension\Registry\PublisherRegistry;
use App\Addons\SeoContentAi\Extension\Registry\SeoProviderRegistry;
use App\Addons\SeoContentAi\Extension\Registry\WorkflowExtensionRegistry;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Publishing\ContentPublisherRegistry;

final class ExtensionContext
{
    public function __construct(
        private readonly PublisherRegistry $publishers,
        private readonly ContentPublisherRegistry $contentPublishers,
        private readonly AiProviderRegistry $aiProviders,
        private readonly SeoProviderRegistry $seoProviders,
        private readonly PipelineRegistry $pipelines,
        private readonly ExtensionCapabilityRegistry $capabilities,
        private readonly PromptHookExtensionRegistry $promptHooks,
        private readonly MediaProcessorRegistry $mediaProcessors,
        private readonly WorkflowExtensionRegistry $workflows,
        private readonly ExtensionEventBus $events,
        private readonly ExtensionRegistry $extensions,
    ) {}

    public function publishers(): PublisherRegistry
    {
        return $this->publishers;
    }

    public function contentPublishers(): ContentPublisherRegistry
    {
        return $this->contentPublishers;
    }

    public function aiProviders(): AiProviderRegistry
    {
        return $this->aiProviders;
    }

    public function seoProviders(): SeoProviderRegistry
    {
        return $this->seoProviders;
    }

    public function pipelines(): PipelineRegistry
    {
        return $this->pipelines;
    }

    public function capabilities(): ExtensionCapabilityRegistry
    {
        return $this->capabilities;
    }

    public function promptHooks(): PromptHookExtensionRegistry
    {
        return $this->promptHooks;
    }

    public function mediaProcessors(): MediaProcessorRegistry
    {
        return $this->mediaProcessors;
    }

    public function workflows(): WorkflowExtensionRegistry
    {
        return $this->workflows;
    }

    public function events(): ExtensionEventBus
    {
        return $this->events;
    }

    public function extensions(): ExtensionRegistry
    {
        return $this->extensions;
    }
}
