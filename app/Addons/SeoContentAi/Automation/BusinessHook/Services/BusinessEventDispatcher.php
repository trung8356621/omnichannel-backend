<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Automation\BusinessHook\Services;

use App\Addons\SeoContentAi\Automation\BusinessHook\Enums\AutomationExecutionStatus;
use App\Addons\SeoContentAi\Automation\BusinessHook\Enums\AutomationRunMode;
use App\Addons\SeoContentAi\Automation\BusinessHook\Enums\BusinessHookErrorCode;
use App\Addons\SeoContentAi\Automation\BusinessHook\Jobs\ExecuteAutomationRuleJob;
use App\Addons\SeoContentAi\Automation\BusinessHook\Models\AutomationExecution;
use App\Addons\SeoContentAi\Automation\BusinessHook\Models\AutomationRule;
use App\Addons\SeoContentAi\Automation\BusinessHook\Models\BusinessEvent;
use App\Addons\SeoContentAi\Automation\BusinessHook\Registry\BusinessEventRegistry;
use App\Addons\SeoContentAi\Automation\BusinessHook\Support\AutomationLoopGuard;
use App\Addons\SeoContentAi\Automation\BusinessHook\Support\AutomationSnapshotSanitizer;
use App\Addons\SeoContentAi\Automation\Exceptions\AutomationException;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Models\SeoProjectRun;
use App\Addons\SeoContentAi\Models\SeoProjectTask;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class BusinessEventDispatcher
{
    public function __construct(
        private readonly BusinessEventRegistry $eventRegistry,
        private readonly AutomationRuleMatcher $matcher,
        private readonly AutomationExecutionService $executionService,
        private readonly AutomationLoopGuard $loopGuard,
        private readonly AutomationSnapshotSanitizer $sanitizer,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $context
     */
    public function dispatch(
        string $eventName,
        Model|string|null $subject = null,
        array $payload = [],
        array $context = [],
        ?string $eventUuid = null,
    ): BusinessEvent {
        if (! $this->eventRegistry->has($eventName)) {
            throw new AutomationException(
                BusinessHookErrorCode::EventNotRegistered->value,
                "Business event [{$eventName}] is not registered.",
            );
        }

        $eventUuid ??= (string) Str::uuid();

        $existing = BusinessEvent::query()->where('event_uuid', $eventUuid)->first();
        if ($existing instanceof BusinessEvent) {
            return $existing;
        }

        $subjectType = null;
        $subjectId = null;
        $subjectData = [];

        if ($subject instanceof Model) {
            $subjectType = $subject::class;
            $subjectId = (int) $subject->getKey();
            $subjectData = $this->extractSubjectData($subject);
        } elseif (is_string($subject) && $subject !== '') {
            $subjectType = $subject;
        }

        $siteId = $this->nullableInt($payload['site_id'] ?? $context['site_id'] ?? $subjectData['site_id'] ?? null);
        $projectId = $this->nullableInt($payload['project_id'] ?? $context['project_id'] ?? $subjectData['project_id'] ?? null);

        $context = $this->enrichContext($context, $eventUuid, $eventName);

        try {
            $context = $this->loopGuard->assertAllowed($context, $eventName, null);
        } catch (AutomationException $e) {
            Log::warning('automation.event.blocked', [
                'event_name' => $eventName,
                'error_code' => $e->errorCode,
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }

        $payloadErrors = $this->eventRegistry->validatePayload($eventName, $payload);
        if ($payloadErrors !== []) {
            Log::debug('automation.event.payload_soft_invalid', [
                'event_name' => $eventName,
                'errors' => $payloadErrors,
            ]);
        }

        $event = BusinessEvent::query()->create([
            'event_uuid' => $eventUuid,
            'event_name' => $eventName,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'site_id' => $siteId,
            'project_id' => $projectId,
            'payload' => $this->sanitizer->sanitize($payload) ?? [],
            'context' => $this->sanitizer->sanitize($context) ?? [],
            'occurred_at' => now(),
            'created_at' => now(),
        ]);

        $schedule = function () use ($event, $subjectData): void {
            $this->scheduleMatchingRules($event, $subjectData);
        };

        if (DB::connection('omi_seo_ai')->transactionLevel() > 0) {
            DB::connection('omi_seo_ai')->afterCommit($schedule);
        } else {
            $schedule();
        }

        return $event;
    }

    /**
     * @param  array<string, mixed>  $subjectData
     */
    private function scheduleMatchingRules(BusinessEvent $event, array $subjectData): void
    {
        $rules = $this->matcher->match($event, $subjectData);

        foreach ($rules as $rule) {
            try {
                $execution = $this->executionService->createPendingExecution($event, $rule);
                if (! $execution instanceof AutomationExecution) {
                    continue;
                }

                if ($rule->run_mode === AutomationRunMode::Sync->value) {
                    $this->executionService->run($execution->id);
                } else {
                    ExecuteAutomationRuleJob::dispatch($execution->id);
                }
            } catch (\Throwable $e) {
                Log::error('automation.schedule_failed', [
                    'event_uuid' => $event->event_uuid,
                    'rule_id' => $rule->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function enrichContext(array $context, string $eventUuid, string $eventName): array
    {
        $context['event_uuid'] = $context['event_uuid'] ?? $eventUuid;
        $context['root_event_uuid'] = $context['root_event_uuid'] ?? $eventUuid;
        $context['automation_depth'] = (int) ($context['automation_depth'] ?? 0);
        $context['automation_chain'] = is_array($context['automation_chain'] ?? null)
            ? $context['automation_chain']
            : [];
        $context['triggered_event_name'] = $eventName;

        if (! isset($context['actor_id']) && auth()->id() !== null) {
            $context['actor_id'] = (int) auth()->id();
        }

        if (! isset($context['correlation_id'])) {
            $context['correlation_id'] = (string) Str::uuid();
        }

        return $context;
    }

    /**
     * @return array<string, mixed>
     */
    private function extractSubjectData(Model $subject): array
    {
        if ($subject instanceof SeoArticle) {
            return [
                'id' => (int) $subject->id,
                'site_id' => $this->nullableInt($subject->site_id ?? null),
                'post_type' => $subject->post_type ?? null,
                'status' => $subject->status ?? null,
                'title' => $subject->title ?? null,
            ];
        }

        if ($subject instanceof SeoProjectTask) {
            return [
                'id' => (int) $subject->id,
                'project_id' => $this->nullableInt($subject->project_id ?? null),
                'site_id' => $this->nullableInt($subject->site_id ?? null),
                'article_id' => $this->nullableInt($subject->article_id ?? null),
                'status' => $subject->status ?? null,
                'post_type' => $subject->post_type ?? null,
            ];
        }

        if ($subject instanceof SeoProjectRun) {
            return [
                'id' => (int) $subject->id,
                'project_id' => $this->nullableInt($subject->project_id ?? null),
                'status' => $subject->status ?? null,
            ];
        }

        return [
            'id' => (int) $subject->getKey(),
        ];
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $int = (int) $value;

        return $int > 0 ? $int : null;
    }
}
