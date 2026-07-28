<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Enums\KeywordIntelligence\KeywordSearchIntent;
use App\Addons\SeoContentAi\Services\SerpIntelligence\Enums\SerpIntentReconciliationCode;
use App\Addons\SeoContentAi\Services\SerpIntelligence\KeywordSerpIntentReconciler;
use PHPUnit\Framework\TestCase;

final class SerpKeywordIntegrationTest extends TestCase
{
    private KeywordSerpIntentReconciler $reconciler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reconciler = new KeywordSerpIntentReconciler;
    }

    public function test_manual_intent_never_overwritten_by_serp(): void
    {
        $result = $this->reconciler->reconcile([
            'manual_intent' => KeywordSearchIntent::Commercial->value,
            'field_sources' => ['intent' => 'manual'],
            'serp_intent' => KeywordSearchIntent::Informational->value,
            'serp_confidence' => 0.92,
            'classified_intent' => KeywordSearchIntent::Informational->value,
        ]);

        self::assertTrue($result['manual_locked']);
        self::assertSame(KeywordSearchIntent::Commercial->value, $result['effective_intent']);
        self::assertSame(SerpIntentReconciliationCode::Consistent, $result['code']);
    }

    public function test_serp_can_influence_when_manual_not_locked(): void
    {
        $result = $this->reconciler->reconcile([
            'serp_intent' => KeywordSearchIntent::Informational->value,
            'serp_confidence' => 0.8,
            'classified_intent' => KeywordSearchIntent::Informational->value,
        ]);

        self::assertFalse($result['manual_locked']);
        self::assertSame(KeywordSearchIntent::Informational->value, $result['effective_intent']);
    }
}
