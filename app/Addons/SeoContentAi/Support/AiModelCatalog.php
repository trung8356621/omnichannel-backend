<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Support;

use App\Models\ApiConnection;

final class AiModelCatalog
{
    /**
     * @return array<string, string>
     */
    public static function optionsForConnection(?ApiConnection $connection): array
    {
        if ($connection === null) {
            return GeminiModelCatalog::selectOptions();
        }

        return match ($connection->provider) {
            'claude' => self::claudeOptions(),
            'gemini' => GeminiModelCatalog::selectOptions(),
            default => [],
        };
    }

    public static function defaultForConnection(?ApiConnection $connection): string
    {
        if ($connection === null) {
            return GeminiModelCatalog::defaultModel();
        }

        $configured = trim((string) ($connection->default_model ?? ''));

        if ($connection->provider === 'gemini') {
            return $configured !== ''
                ? GeminiModelCatalog::resolve($configured)
                : GeminiModelCatalog::defaultModel();
        }

        if ($connection->provider === 'claude') {
            return $configured !== '' ? $configured : 'claude-3-5-sonnet-20240620';
        }

        return $configured;
    }

    /**
     * @return array<string, string>
     */
    public static function claudeOptions(): array
    {
        return [
            'claude-sonnet-4-20250514' => 'Claude Sonnet 4',
            'claude-3-5-sonnet-20240620' => 'Claude 3.5 Sonnet',
            'claude-3-opus-20240229' => 'Claude 3 Opus',
            'claude-3-haiku-20240307' => 'Claude 3 Haiku',
        ];
    }
}
