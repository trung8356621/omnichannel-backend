<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Enums;

enum ContentProjectItemErrorSource: string
{
    case None = 'none';
    case Generation = 'generation';
    case Publish = 'publish';
    case Execution = 'execution';
}
