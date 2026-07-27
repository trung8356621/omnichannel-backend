<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Extension\Contracts;

use App\Addons\SeoContentAi\Extension\Contracts\Ai\AiExecutionContext;
use App\Addons\SeoContentAi\Extension\Contracts\Ai\AiImageRequest;
use App\Addons\SeoContentAi\Extension\Contracts\Ai\AiImageResult;
use App\Addons\SeoContentAi\Extension\Contracts\Ai\AiProviderHealthResult;

/**
 * Real image-generation boundary for AI provider extensions.
 */
interface AiImageProviderInterface
{
    /**
     * Registry key, e.g. "gemini", "imagen".
     */
    public function key(): string;

    public function generateImage(AiImageRequest $request, AiExecutionContext $context): AiImageResult;

    public function health(AiExecutionContext $context): AiProviderHealthResult;
}
