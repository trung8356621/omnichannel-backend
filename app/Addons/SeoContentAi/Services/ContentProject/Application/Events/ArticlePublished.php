<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\ContentProject\Application\Events;

final class ArticlePublished
{
    public function __construct(
        public readonly int $projectId,
        public readonly int $itemId,
        public readonly int $articleId,
        public readonly int $wpPostId,
    ) {}
}
