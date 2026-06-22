<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Skipped addon slugs
    |--------------------------------------------------------------------------
    |
    | Slug trong addon.json sẽ bị bỏ qua khi AddonManager::discover() và khi
    | AppServiceProvider đăng ký provider từ bảng services (dù is_active=1).
    |
    */

    'skip_slugs' => array_values(array_filter(array_map(
        static fn (string $slug): string => trim($slug),
        explode(',', (string) env('ADDON_SKIP_SLUGS', 'wp-headless')),
    ))),

];
