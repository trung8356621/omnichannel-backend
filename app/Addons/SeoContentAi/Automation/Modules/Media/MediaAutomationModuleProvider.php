<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Automation\Modules\Media;

use App\Addons\SeoContentAi\Automation\BusinessHook\Data\BusinessEventDefinition;
use App\Addons\SeoContentAi\Automation\BusinessHook\Enums\BusinessEventName;
use App\Addons\SeoContentAi\Automation\Platform\AutomationModuleContext;
use App\Addons\SeoContentAi\Automation\Platform\Contracts\AutomationModuleProvider;
use App\Addons\SeoContentAi\Models\SeoMedia;

final class MediaAutomationModuleProvider implements AutomationModuleProvider
{
    public function id(): string
    {
        return 'media';
    }

    public function register(AutomationModuleContext $context): void
    {
        foreach ([
            BusinessEventName::MediaUploaded,
            BusinessEventName::MediaProcessed,
            BusinessEventName::MediaFailed,
        ] as $enum) {
            $context->events->register(new BusinessEventDefinition(
                name: $enum->value,
                subject: SeoMedia::class,
                payloadSchema: [
                    'media_id' => ['type' => 'mixed', 'required' => true],
                    'site_id' => ['type' => 'mixed', 'required' => false],
                ],
                description: $enum->value,
                module: 'media',
            ));
        }
    }
}
