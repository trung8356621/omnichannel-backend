<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\ContentProject\Application\Publishing;

interface ContentPublisher
{
    public function publish(ArticlePublishPayload $payload): PublishResult;

    public function findByExternalReference(int $siteId, string $externalReference): ?int;
}
