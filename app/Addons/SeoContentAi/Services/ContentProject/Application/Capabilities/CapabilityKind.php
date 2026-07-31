<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\ContentProject\Application\Capabilities;

use App\Addons\SeoContentAi\Services\SiteSync\Contracts\SiteSyncSchema;

/**
 * Separates MCP/Agent system actions from WordPress site feature availability.
 * Do not mix these namespaces in one catalog object without this kind field.
 */
final class CapabilityKind
{
    public const SYSTEM_ACTION = 'system_action';

    public const SITE_FEATURE = 'site_feature';

    public static function isSiteFeatureKey(string $key): bool
    {
        return in_array($key, SiteSyncSchema::CAPABILITY_KEYS, true);
    }

    public static function classify(string $nameOrKey): string
    {
        return self::isSiteFeatureKey($nameOrKey)
            ? self::SITE_FEATURE
            : self::SYSTEM_ACTION;
    }
}
