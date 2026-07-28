<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Enums\Gsc;

enum GscSearchType: string
{
    case Web = 'web';
    case Image = 'image';
    case Video = 'video';
    case News = 'news';
    case Discover = 'discover';
    case GoogleNews = 'google_news';
}
