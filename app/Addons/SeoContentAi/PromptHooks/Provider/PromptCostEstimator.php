<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\PromptHooks\Provider;

interface PromptCostEstimator
{
    /**
     * @param  array{provider?: ?string, model?: ?string, input_tokens?: ?int, output_tokens?: ?int}  $usage
     */
    public function estimate(array $usage): ?float;
}
