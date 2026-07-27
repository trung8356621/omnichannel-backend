<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Extension\Builtin\LocalSeo;

use App\Addons\SeoContentAi\Extension\Contracts\ExtensionProvider;
use App\Addons\SeoContentAi\Extension\ExtensionContext;

final class LocalSeoExtensionProvider implements ExtensionProvider
{
    public function __construct(
        private readonly LocalSeoProvider $provider,
        private readonly LocalSeoHealthDriver $healthDriver,
    ) {}

    public function id(): string
    {
        return 'local-seo';
    }

    public function register(ExtensionContext $ctx): void
    {
        $ctx->seoProviders()->registerProvider($this->provider);
        $ctx->seoProviders()->register($this->id(), $this->healthDriver);
    }

    public function boot(ExtensionContext $ctx): void
    {
        unset($ctx);
    }
}
