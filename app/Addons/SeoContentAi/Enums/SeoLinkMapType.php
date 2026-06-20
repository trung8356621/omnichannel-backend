<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Enums;

enum SeoLinkMapType: string
{
    case Internal = 'internal';
    case External = 'external';
    case WikiTrust = 'wiki_trust';
}
