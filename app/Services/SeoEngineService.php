<?php

declare(strict_types=1);

namespace App\Services;

use Omnichannel\Addons\Seo\Services\SeoScoringCalculator;
use Omnichannel\Addons\Seo\Services\SeoScoringEngine;
use Omnichannel\Addons\Seo\Support\SeoScoringRulesRegistry;

final class SeoEngineService
{
    public function __construct(
        private readonly SeoScoringEngine $scoringEngine,
    ) {}

    /**
     * @param  list<array{question?: string, answer?: string}>  $faqsMeta
     * @param  array{seo_title?: string, meta_description?: string, slug?: string, domain?: string, article_length_target?: int}  $context
     * @return array{
     *   seo_score: int,
     *   score: int,
     *   violations: list<string>,
     *   reason_keys: list<string>,
     *   good: list<string>,
     *   errors: list<string>,
     *   warnings: list<string>,
     *   breakdown: array<string, mixed>
     * }
     */
    public function analyzeHtml(
        string $htmlContent,
        string $targetKeyword = '',
        array $faqsMeta = [],
        array $context = [],
    ): array {
        $violations = $this->scoringEngine->analyzeViolations(
            $htmlContent,
            $targetKeyword,
            $faqsMeta,
            $context,
        );

        $score = SeoScoringCalculator::scoreFromViolations($violations);
        $lines = SeoScoringCalculator::violationLines($violations);
        $errors = array_map(
            static fn (array $line): string => sprintf('-%d: %s', $line['deduction'], $line['message']),
            $lines,
        );

        return [
            'seo_score' => $score,
            'score' => $score,
            'violations' => $violations,
            'reason_keys' => $violations,
            'good' => $violations === [] ? [__('seo_rules.all_passed')] : [],
            'errors' => $errors,
            'warnings' => [],
            'breakdown' => [],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function scoringMessagesForLocale(?string $locale = null): array
    {
        return SeoScoringRulesRegistry::messagesForLocale($locale);
    }
}
