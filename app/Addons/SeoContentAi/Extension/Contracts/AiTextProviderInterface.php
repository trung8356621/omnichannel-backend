<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Extension\Contracts;

use App\Addons\SeoContentAi\Extension\Contracts\Ai\AiExecutionContext;
use App\Addons\SeoContentAi\Extension\Contracts\Ai\AiProviderHealthResult;
use App\Addons\SeoContentAi\Extension\Contracts\Ai\AiTextRequest;
use App\Addons\SeoContentAi\Extension\Contracts\Ai\AiTextResult;

interface AiTextProviderInterface
{
    public function key(): string;

    public function supportsModel(string $model): bool;

    public function generate(AiTextRequest $request, AiExecutionContext $context): AiTextResult;

    public function health(AiExecutionContext $context): AiProviderHealthResult;
}
