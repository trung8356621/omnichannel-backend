<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Automation\Support;

use App\Addons\SeoContentAi\Automation\Data\ActionContext;
use App\Addons\SeoContentAi\Automation\Data\ActionResult;
use App\Addons\SeoContentAi\Automation\Data\EventEnvelope;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Support\SeoAccessControl;
use Illuminate\Support\Facades\Cache;

final class ActionSupport
{
    public static function assertMutable(ActionContext $context): ?ActionResult
    {
        if (self::isSystemOrigin($context)) {
            return null;
        }

        if (! SeoAccessControl::canMutateInSeoPanel()) {
            return ActionResult::failure('forbidden', 'Actor is not allowed to mutate SEO content.');
        }

        return null;
    }

    public static function isSystemOrigin(ActionContext $context): bool
    {
        $origin = strtolower($context->origin);

        return str_starts_with($origin, 'system.')
            || str_starts_with($origin, 'foundation.')
            || $origin === 'system'
            || $origin === 'automation.test';
    }

    public static function findArticle(int $articleId): ?SeoArticle
    {
        if ($articleId <= 0) {
            return null;
        }

        $article = SeoArticle::query()->find($articleId);

        return $article instanceof SeoArticle ? $article : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function articleEvent(
        string $eventKey,
        ActionContext $context,
        int $articleId,
        array $payload = [],
    ): EventEnvelope {
        return EventEnvelope::make(
            eventKey: $eventKey,
            entity: ['type' => 'article', 'id' => $articleId],
            context: [
                'correlation_id' => $context->correlationId,
                'causation_id' => $context->causationId,
                'origin' => $context->origin,
                'actor_id' => $context->actorId,
                'team_id' => $context->teamId,
                'site_id' => $context->siteId,
                'connection_id' => $context->connectionId,
            ],
            payload: $payload,
        );
    }

    /**
     * @template T
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withArticleLock(int $articleId, callable $callback): mixed
    {
        $lock = Cache::lock('automation-article-'.$articleId, 30);

        if (! $lock->get()) {
            throw new \RuntimeException('Could not acquire article automation lock.');
        }

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }
}
