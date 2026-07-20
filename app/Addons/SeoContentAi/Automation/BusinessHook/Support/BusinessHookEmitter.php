<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Automation\BusinessHook\Support;

use App\Addons\SeoContentAi\Automation\BusinessHook\Enums\BusinessEventName;
use App\Addons\SeoContentAi\Automation\BusinessHook\Services\BusinessEventDispatcher;
use App\Addons\SeoContentAi\Automation\Data\EventEnvelope;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Models\SeoProjectRun;
use App\Addons\SeoContentAi\Models\SeoProjectTask;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Helper emit domain business events từ application services.
 */
final class BusinessHookEmitter
{
    public function __construct(
        private readonly BusinessEventDispatcher $dispatcher,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $context
     */
    public function emit(
        BusinessEventName|string $event,
        ?Model $subject = null,
        array $payload = [],
        array $context = [],
        ?string $eventUuid = null,
    ): void {
        $name = $event instanceof BusinessEventName ? $event->value : $event;

        try {
            $this->dispatcher->dispatch(
                eventName: $name,
                subject: $subject,
                payload: $payload,
                context: $context,
                eventUuid: $eventUuid,
            );
        } catch (\Throwable $e) {
            Log::warning('business_hook.emit_failed', [
                'event' => $name,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function articleArchived(SeoArticle $article, array $context = []): void
    {
        $this->emit(BusinessEventName::ArticleArchived, $article, [
            'article_id' => (int) $article->id,
            'site_id' => (int) ($article->site_id ?? 0) ?: null,
            'status' => 'archived',
        ], $context);
    }

    public function articleRestored(SeoArticle $article, array $context = []): void
    {
        $this->emit(BusinessEventName::ArticleRestored, $article, [
            'article_id' => (int) $article->id,
            'site_id' => (int) ($article->site_id ?? 0) ?: null,
            'status' => 'restored',
        ], $context);
    }

    public function taskFailed(SeoProjectTask $task, array $context = []): void
    {
        $this->emit(BusinessEventName::ContentProjectTaskFailed, $task, [
            'task_id' => (int) $task->id,
            'project_id' => (int) ($task->project_id ?? 0) ?: null,
            'site_id' => (int) ($task->site_id ?? 0) ?: null,
            'article_id' => (int) ($task->article_id ?? 0) ?: null,
            'status' => 'failed',
        ], $context);
    }

    public function taskArchived(SeoProjectTask $task, array $context = []): void
    {
        $this->emit(BusinessEventName::ContentProjectTaskArchived, $task, [
            'task_id' => (int) $task->id,
            'project_id' => (int) ($task->project_id ?? 0) ?: null,
            'status' => 'archived',
        ], $context);
    }

    public function runCompleted(SeoProjectRun $run, array $context = []): void
    {
        $this->emit(BusinessEventName::ContentProjectRunCompleted, $run, [
            'run_id' => (int) $run->id,
            'project_id' => (int) ($run->project_id ?? 0) ?: null,
            'status' => 'completed',
        ], $context);
    }

    public function wordpressSynced(SeoArticle $article, array $result = [], array $context = []): void
    {
        $this->emit(BusinessEventName::WordpressSynced, $article, [
            'article_id' => (int) $article->id,
            'site_id' => (int) ($article->site_id ?? 0) ?: null,
            'wp_post_id' => $result['wp_post_id'] ?? $article->wp_post_id ?? null,
            'status' => 'synced',
        ], $context);
    }

    public function wordpressSyncFailed(SeoArticle $article, string $error, array $context = []): void
    {
        $this->emit(BusinessEventName::WordpressSyncFailed, $article, [
            'article_id' => (int) $article->id,
            'site_id' => (int) ($article->site_id ?? 0) ?: null,
            'error' => $error,
            'status' => 'failed',
        ], $context);
    }

    /**
     * Emit both ActionRunner envelope (log bridge) friendly helper.
     *
     * @param  array{type: string, id: int|string|null}  $entity
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $payload
     */
    public function envelope(string $eventKey, array $entity, array $context = [], array $payload = []): EventEnvelope
    {
        return EventEnvelope::make(
            eventKey: $eventKey,
            entity: $entity,
            context: $context,
            payload: $payload,
            eventId: (string) Str::uuid(),
        );
    }
}
