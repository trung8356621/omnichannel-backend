<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Enums\Gsc;

enum GscPropertyType: string
{
    case Domain = 'domain';
    case UrlPrefix = 'url_prefix';
    case Manual = 'manual';
}
