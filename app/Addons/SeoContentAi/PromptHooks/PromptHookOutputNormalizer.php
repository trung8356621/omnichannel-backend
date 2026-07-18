<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\PromptHooks;

use App\Addons\SeoContentAi\PromptHooks\Data\PromptHookDefinition;
use App\Addons\SeoContentAi\PromptHooks\Exceptions\PromptHookException;
use App\Addons\SeoContentAi\PromptHooks\Support\PromptHookErrorCode;

final class PromptHookOutputNormalizer
{
    /**
     * @return array{format: string, raw: string, value: string}
     */
    public function normalize(PromptHookDefinition $definition, string $rawOutput): array
    {
        $raw = $rawOutput;
        $value = $rawOutput;

        foreach ($definition->outputNormalizeSteps() as $step) {
            $value = match ($step) {
                'trim' => trim($value),
                'strip_markdown_fence' => $this->stripMarkdownFence($value),
                'strip_wrapping_quotes' => $this->stripWrappingQuotes($value),
                'first_non_empty_line' => $this->firstNonEmptyLine($value),
                default => $value,
            };
        }

        $validation = $definition->outputValidation();
        if (($validation['not_empty'] ?? false) === true && trim($value) === '') {
            throw new PromptHookException(
                PromptHookErrorCode::HookOutputInvalid,
                "Hook [{$definition->key}] returned empty output.",
            );
        }

        return [
            'format' => $definition->outputFormat(),
            'raw' => $raw,
            'value' => $value,
        ];
    }

    private function stripMarkdownFence(string $value): string
    {
        $trimmed = trim($value);
        if (preg_match('/^```(?:\w+)?\s*\n?(.*?)\n?```$/s', $trimmed, $matches) === 1) {
            return trim($matches[1]);
        }

        return $value;
    }

    private function stripWrappingQuotes(string $value): string
    {
        $trimmed = trim($value);
        if (strlen($trimmed) >= 2) {
            $first = $trimmed[0];
            $last = $trimmed[strlen($trimmed) - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                return trim(substr($trimmed, 1, -1));
            }
        }

        return $value;
    }

    private function firstNonEmptyLine(string $value): string
    {
        $lines = preg_split('/\R/u', $value) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line !== '') {
                return $line;
            }
        }

        return trim($value);
    }
}
