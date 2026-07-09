<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Support\SeoScoringRulesRegistry;
use App\Models\WpOption;
use Illuminate\Database\QueryException;

final class SeoScoringSettingsService
{
    public const OPTION_KEY = 'seo_scoring_rules_settings';

    /** @var array<string, array{enabled: bool, deduction: int}>|null */
    private ?array $inMemoryOverrides = null;

    public static function withOverrides(array $overrides): self
    {
        $service = new self();
        $service->inMemoryOverrides = $overrides;

        return $service;
    }

    /**
     * @return array<string, array{enabled: bool, deduction: int}>
     */
    public function getRuleOverrides(): array
    {
        if ($this->inMemoryOverrides !== null) {
            return $this->inMemoryOverrides;
        }

        $raw = $this->readStoredOption();
        $rules = is_array($raw)
            ? (is_array($raw['rules'] ?? null) ? $raw['rules'] : $raw)
            : [];
        $normalized = [];

        foreach (SeoScoringRulesRegistry::defaultRules() as $default) {
            $key = $default['key'];
            $item = is_array($rules[$key] ?? null) ? $rules[$key] : [];
            $normalized[$key] = [
                'enabled' => array_key_exists('enabled', $item)
                    ? (bool) $item['enabled']
                    : true,
                'deduction' => $this->normalizeDeduction(
                    $item['deduction'] ?? null,
                    (int) $default['deduction'],
                ),
            ];
        }

        return $normalized;
    }

    /**
     * @param  array<string, array{enabled?: bool, deduction?: int}>  $rules
     */
    public function saveRuleOverrides(array $rules): void
    {
        $payload = ['rules' => []];

        foreach (SeoScoringRulesRegistry::defaultRules() as $default) {
            $key = $default['key'];
            $item = is_array($rules[$key] ?? null) ? $rules[$key] : [];

            $payload['rules'][$key] = [
                'enabled' => array_key_exists('enabled', $item)
                    ? (bool) $item['enabled']
                    : true,
                'deduction' => $this->normalizeDeduction(
                    $item['deduction'] ?? null,
                    (int) $default['deduction'],
                ),
            ];
        }

        WpOption::set(self::OPTION_KEY, $payload);
        $this->inMemoryOverrides = $payload['rules'];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readStoredOption(): ?array
    {
        try {
            $raw = WpOption::get(self::OPTION_KEY, []);

            return is_array($raw) ? $raw : null;
        } catch (QueryException) {
            return null;
        }
    }

    /**
     * @return list<array{key: string, deduction: int, enabled: bool, locale_key: string}>
     */
    public function effectiveRules(): array
    {
        $overrides = $this->getRuleOverrides();

        return array_map(
            static function (array $default) use ($overrides): array {
                $key = $default['key'];
                $override = $overrides[$key] ?? [];

                return [
                    'key' => $key,
                    'deduction' => (int) ($override['deduction'] ?? $default['deduction']),
                    'enabled' => (bool) ($override['enabled'] ?? true),
                    'locale_key' => $default['locale_key'],
                ];
            },
            SeoScoringRulesRegistry::defaultRules(),
        );
    }

    public function isRuleEnabled(string $key): bool
    {
        return (bool) ($this->getRuleOverrides()[$key]['enabled'] ?? true);
    }

    public function deductionFor(string $key): int
    {
        if (! $this->isRuleEnabled($key)) {
            return 0;
        }

        return (int) ($this->getRuleOverrides()[$key]['deduction']
            ?? SeoScoringRulesRegistry::defaultDeductionFor($key));
    }

    private function normalizeDeduction(mixed $value, int $fallback): int
    {
        $parsed = is_numeric($value) ? (int) $value : $fallback;

        return max(0, min(SeoScoringRulesRegistry::BASE_SCORE, $parsed));
    }
}
