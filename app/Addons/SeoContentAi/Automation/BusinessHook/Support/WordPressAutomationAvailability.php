<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Automation\BusinessHook\Support;

use App\Addons\SeoContentAi\Automation\BusinessHook\Enums\AutomationActionCode;
use App\Addons\SeoContentAi\Automation\BusinessHook\Services\AutomationAvailabilityGate;

/**
 * @deprecated Use AutomationAvailabilityGate — kept as thin facade for existing callers.
 */
final class WordPressAutomationAvailability
{
    public function __construct(
        private readonly AutomationAvailabilityGate $gate,
    ) {}

    public function isWordpressSyncEnabled(?int $siteId = null): bool
    {
        return $this->gate->isActionAvailableForManual(
            AutomationActionCode::WordpressArticleSync->value,
            $siteId,
        );
    }

    public function enabledWordpressSyncRuleCount(?int $siteId = null): int
    {
        return $this->gate->resolveManualRules(
            AutomationActionCode::WordpressArticleSync->value,
            $siteId,
        )->count();
    }

    public function disabledMessage(): string
    {
        return (string) __('seo-content-ai::filament.automation.gate.rule_disabled', [
            'action' => AutomationActionCode::WordpressArticleSync->value,
        ]);
    }
}
