<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\PromptHooks\Provider;

use App\Addons\SeoContentAi\PromptHooks\Runtime\RenderedPromptRequest;

interface PromptProviderAdapter
{
    public function capabilities(): PromptProviderCapabilities;

    public function generate(RenderedPromptRequest $request, PromptStructuredStrategy $strategy): PromptProviderResponse;
}
