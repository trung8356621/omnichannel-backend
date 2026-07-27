<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Extension\Builtin\AiProviders;

use App\Addons\SeoContentAi\Extension\Contracts\ExtensionProvider;
use App\Addons\SeoContentAi\Extension\ExtensionContext;

final class AiProvidersExtensionProvider implements ExtensionProvider
{
    public function __construct(
        private readonly GeminiAiTextProvider $gemini,
        private readonly ClaudeAiTextProvider $claude,
        private readonly AiProvidersHealthDriver $healthDriver,
    ) {}

    public function id(): string
    {
        return 'ai-providers';
    }

    public function register(ExtensionContext $ctx): void
    {
        $ctx->aiProviders()->registerText($this->gemini);
        $ctx->aiProviders()->registerText($this->claude);
        $ctx->aiProviders()->register($this->id(), $this->healthDriver);
    }

    public function boot(ExtensionContext $ctx): void
    {
        unset($ctx);
    }
}
