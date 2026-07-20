<?php

declare(strict_types=1);

return [
    'modules' => [
        \App\Addons\SeoContentAi\Automation\Modules\Core\CoreAutomationModuleProvider::class => true,
        \App\Addons\SeoContentAi\Automation\Modules\WordPress\WordPressAutomationModuleProvider::class => true,
        \App\Addons\SeoContentAi\Automation\Modules\Content\ContentAutomationModuleProvider::class => true,
        \App\Addons\SeoContentAi\Automation\Modules\Seo\SeoAutomationModuleProvider::class => true,
        \App\Addons\SeoContentAi\Automation\Modules\Media\MediaAutomationModuleProvider::class => true,
        \App\Addons\SeoContentAi\Automation\Modules\Sample\SampleAutomationModuleProvider::class => false,
    ],
];
