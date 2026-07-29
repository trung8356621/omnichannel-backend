<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Pages;

use App\Addons\SeoContentAi\Models\AgentWorkspace\SeoAgentConversation;
use App\Addons\SeoContentAi\Models\AgentWorkspace\SeoAgentMessage;
use App\Addons\SeoContentAi\Services\AgentWorkspace\AgentCapabilityDiagnosticsService;
use App\Addons\SeoContentAi\Services\AgentWorkspace\AgentChatTemplateRegistry;
use App\Addons\SeoContentAi\Services\AgentWorkspace\AgentConversationService;
use App\Addons\SeoContentAi\Services\AgentWorkspace\AgentIntentRouter;
use App\Addons\SeoContentAi\Services\AgentWorkspace\AgentSkillAvailabilityService;
use App\Addons\SeoContentAi\Services\AgentWorkspace\AgentSkillRecommendationService;
use App\Addons\SeoContentAi\Services\AgentWorkspace\AgentSkillRegistry;
use App\Addons\SeoContentAi\Services\AgentWorkspace\AgentWorkspaceApplicationService;
use App\Addons\SeoContentAi\Services\AgentWorkspace\AgentWorkspaceContextService;
use App\Addons\SeoContentAi\Services\AgentWorkspace\Cli\AgentCliArgumentSuggestService;
use App\Addons\SeoContentAi\Services\AgentWorkspace\Cli\AgentCliCommandCatalog;
use App\Addons\SeoContentAi\Services\AgentWorkspace\Cli\AgentCliCommandParser;
use App\Addons\SeoContentAi\Services\AgentWorkspace\ConversationalAgentFlowService;
use App\Addons\SeoContentAi\Services\AgentWorkspace\Dtos\AgentIntentResolution;
use App\Addons\SeoContentAi\Services\AgentWorkspace\Dtos\AgentWorkspaceContext;
use App\Addons\SeoContentAi\Models\AgentWorkspace\SeoAgentExecution;
use App\Addons\SeoContentAi\Support\SeoAccessControl;
use App\Models\User;
use App\Support\RuntimeLogger;
use Filament\Notifications\Notification;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Support\Str;
use Throwable;

/**
 * Agent Workspace — /seo/{connection_hash}/agent
 *
 * @see docs/AGENT_WORKSPACE.md
 */
final class AgentWorkspacePage extends SeoPanelPage
{
    protected static ?string $slug = 'agent';

    protected static ?string $navigationIcon = 'heroicon-o-cpu-chip';

    protected static ?string $navigationGroup = null;

    protected static ?int $navigationSort = 5;

    protected static bool $shouldRegisterNavigation = true;

    protected static string $view = 'seo-content-ai::filament.pages.agent-workspace';

    private const DRAFT_CONTEXT_KEY = 'active_interaction_draft';

    private const KEYWORD_CONTEXT_KEY = 'cli_keyword_context';

    /**
     * Suppress "append user command" when skill was resolved from an already-sent composer message.
     */
    private bool $suppressUserCommandAppend = false;

    public string $activePanel = 'chat';

    public ?string $conversationRef = null;

    public string $composerText = '';

    public bool $composerSubmitting = false;

    public string $composerError = '';

    public string $paletteQuery = '';

    public bool $showPalette = false;

    /**
     * Chat-only conversation draft state machine.
     */
    public string $conversationFlowState = ConversationalAgentFlowService::STATE_IDLE;

    public array $missingInputKeys = [];

    public ?string $currentInputKey = null;

    public ?string $draftRef = null;

    public ?string $confirmationRef = null;

    public bool $draftBusy = false;

    /**
     * When editing: quick-reply selects which field to edit.
     */
    public bool $editFieldChoiceMode = false;

    public ?string $activeSkillKey = null;

    public ?string $activeTemplateKey = null;

    /** @var array<string, mixed> */
    public array $skillForm = [];

    /** @var list<array<string, mixed>> */
    public array $skillFormSchema = [];

    /** @var array<string, mixed>|null */
    public ?array $skillMeta = null;

    /** @var array{status?: string, reason?: string, usable?: bool} */
    public array $skillAvailability = [];

    /** @var array<string, mixed>|null */
    public ?array $previewPayload = null;

    public ?string $pendingExecutionRef = null;

    public ?string $pendingConfirmationToken = null;

    public string $contextNotice = '';

    /** @var array<string, mixed> */
    public array $workspaceContext = [];

    /** @var list<array<string, mixed>> */
    public array $conversations = [];

    /** @var list<array<string, mixed>> */
    public array $messages = [];

    /** @var list<array<string, mixed>> */
    public array $suggestedActions = [];

    /** @var list<array<string, mixed>> */
    public array $recommendedSkills = [];

    /** @var list<array<string, mixed>> */
    public array $paletteSkills = [];

    public ?string $activeCliCommand = null;

    /** @var array{command?: string, description?: string, example?: string}|null */
    public ?array $cliHelpPanel = null;

    /** @var list<array{value: string, label: string}> */
    public array $cliArgumentSuggestions = [];

    /** @var array<int, string> 1-indexed keyword list from last /keyword-suggest */
    public array $keywordContext = [];

    /** @var list<array<string, mixed>> */
    public array $diagnostics = [];

    /** @var array<string, mixed> */
    public array $clarificationAnswers = [];

    /** @var array<string, mixed>|null */
    public ?array $proposedPlan = null;

    /** @var array<string, mixed>|null */
    public ?array $lastPlanningDiagnostics = null;

    public bool $planningInFlight = false;

    /** @var list<array<string, mixed>> */
    public array $knowledgeItems = [];

    /** @var array{scope_type?: string, type?: string, trust_level?: string, status?: string, source_type?: string} */
    public array $knowledgeFilters = [
        'status' => 'active',
    ];

    /** @var array<string, mixed>|null */
    public ?array $knowledgeDetail = null;

    /** @var list<array<string, mixed>> */
    public array $automationItems = [];

    /** @var list<array<string, mixed>> */
    public array $automationHistory = [];

    /** @var array<string, mixed>|null */
    public ?array $automationDetail = null;

    /** @var array<string, mixed>|null */
    public ?array $automationDiagnostics = null;

    /** @var array<string, mixed> */
    public array $operationsOverview = [];

    /** @var list<array<string, mixed>> */
    public array $packItems = [];

    /** @var array<string, mixed>|null */
    public ?array $packDetail = null;

    public string $packStudioTab = 'overview';

    /** @var list<array<string, mixed>> */
    public array $skillGroups = [];

    /** @var array<string, mixed>|null */
    public ?array $v1Readiness = null;

    /** @var array<string, string> */
    public array $workspaceVersion = [];

    public static function canAccess(): bool
    {
        return SeoAccessControl::canAccessManagerFeatures()
            || SeoAccessControl::canMutateContentProjects()
            || SeoAccessControl::canAccessContentFeatures();
    }

    public static function getNavigationLabel(): string
    {
        return __('seo-content-ai::filament.agent_workspace.nav');
    }

    public function getTitle(): string
    {
        return __('seo-content-ai::filament.agent_workspace.title');
    }

    public function getMaxContentWidth(): MaxWidth|string|null
    {
        return MaxWidth::Full;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        try {
            $this->bootWorkspace();
            $this->applyDeepLinkParams();
        } catch (Throwable $e) {
            RuntimeLogger::report($e, ['page' => 'agent-workspace']);
            Notification::make()
                ->title(__('seo-content-ai::filament.agent_workspace.boot_failed'))
                ->body($e->getMessage())
                ->danger()
                ->send();
            $this->workspaceContext = [];
        }
    }

    private function applyDeepLinkParams(): void
    {
        $conversation = request()->query('conversation');
        if (is_string($conversation) && trim($conversation) !== '') {
            $this->selectConversation(trim($conversation));
        }

        $template = request()->query('template');
        if (is_string($template) && trim($template) !== '') {
            $this->selectTemplate(trim($template));

            return;
        }

        $skill = request()->query('skill');
        if (is_string($skill) && trim($skill) !== '') {
            $this->selectSkill(trim($skill));
        }
    }

    public function createConversation(): void
    {
        $context = $this->requireContext();
        $conversation = app(AgentConversationService::class)->create($context);
        $this->conversationRef = (string) $conversation->public_ref;
        $this->clearSkillSelection();
        $this->previewPayload = null;
        $this->refreshConversationState();
    }

    public function selectConversation(string $ref): void
    {
        $ref = $this->normalizeAgentReference($ref);
        if ($ref === '') {
            return;
        }

        $this->conversationRef = $ref;
        $this->clearSkillSelection();
        $this->previewPayload = null;
        $this->refreshConversationState();
    }

    /**
     * Normalize browser-sent reference keys. Browser only sends opaque refs — server resolves.
     */
    private function normalizeAgentReference(string $raw, int $maxLength = 190): string
    {
        $value = trim($raw);
        if ($value === '') {
            return '';
        }

        if (strlen($value) > $maxLength) {
            return '';
        }

        // Reject control characters / obvious injection payloads.
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value) === 1) {
            return '';
        }

        return $value;
    }

    /**
     * @param  list<array<string, mixed>>  $formSchema
     * @return array<string, mixed>
     */
    private function findFieldSchema(array $formSchema, string $key): array
    {
        foreach ($formSchema as $field) {
            if (! is_array($field)) {
                continue;
            }
            if (($field['key'] ?? null) === $key) {
                return $field;
            }
        }

        // Fallback: keep deterministic parsing even if schema was missing.
        return [
            'key' => $key,
            'label' => $key,
            'type' => 'string',
            'required' => true,
            'options' => [],
        ];
    }

    public function answerConversation(string $value): void
    {
        if ($this->draftBusy) {
            return;
        }

        $value = trim($value);
        if ($value === '') {
            return;
        }

        $this->draftBusy = true;

        try {
            $context = $this->requireContext();
            $conversation = $this->requireConversation($context);

            // Refresh draft from persistence when possible.
            $this->hydrateActiveDraftFromConversation($conversation);

            if ($this->conversationFlowState === ConversationalAgentFlowService::STATE_AWAITING_CONFIRMATION) {
                if (mb_strtolower($value) === 'edit') {
                    $this->editFieldChoiceMode = true;
                    $this->currentInputKey = null;
                    $this->conversationFlowState = ConversationalAgentFlowService::STATE_AWAITING_INPUT;

                    $quickReplies = [];
                    foreach ($this->skillFormSchema as $field) {
                        if (! is_array($field)) {
                            continue;
                        }
                        $k = (string) ($field['key'] ?? '');
                        if ($k === '') {
                            continue;
                        }
                        $quickReplies[] = ['label' => (string) ($field['label'] ?? $k), 'value' => $k];
                    }

                    app(AgentConversationService::class)->appendMessage(
                        $conversation,
                        role: 'assistant',
                        messageType: 'agent_question',
                        content: 'Bạn muốn sửa trường nào?',
                        structured: [
                            'input_key' => null,
                            'quick_replies' => $quickReplies,
                        ],
                        createdBy: $context->actorUserId,
                    );

                    $this->persistActiveDraft($conversation, source: 'edit', command: null);
                    $this->refreshMessages($conversation);

                    return;
                }

                $flow = new ConversationalAgentFlowService();
                $parsed = $flow->parseConfirmationAnswer($value);
                if (! ($parsed['ok'] ?? false)) {
                    app(AgentConversationService::class)->appendMessage(
                        $conversation,
                        role: 'assistant',
                        messageType: 'agent_clarification',
                        content: 'Vui lòng trả lời Yes hoặc No.',
                        structured: null,
                        createdBy: $context->actorUserId,
                    );
                    $this->persistActiveDraft($conversation, source: 'clarify', command: null);
                    $this->refreshMessages($conversation);

                    return;
                }

                $answer = (string) $parsed['value'];
                if ($answer === 'no') {
                    if ($this->pendingExecutionRef === null) {
                        $this->clearDraftPersist($conversation);
                        $this->conversationFlowState = ConversationalAgentFlowService::STATE_CANCELLED;
                        $this->refreshMessages($conversation);
                        return;
                    }

                    app(AgentWorkspaceApplicationService::class)->cancelExecution($context, $this->pendingExecutionRef, 'user_cancel');
                    $this->clearDraftPersist($conversation);
                    $this->conversationFlowState = ConversationalAgentFlowService::STATE_CANCELLED;
                    $this->refreshMessages($conversation);

                    return;
                }

                // YES
                if ($this->pendingExecutionRef === null) {
                    app(AgentConversationService::class)->appendMessage(
                        $conversation,
                        role: 'assistant',
                        messageType: 'agent_error',
                        content: 'Thiếu execution_ref. Vui lòng bắt đầu lại.',
                        structured: null,
                        createdBy: $context->actorUserId,
                    );
                    $this->clearDraftPersist($conversation);
                    $this->conversationFlowState = ConversationalAgentFlowService::STATE_FAILED;
                    $this->refreshMessages($conversation);

                    return;
                }

                // Confirm path
                if ($this->confirmationRef !== null && $this->confirmationRef !== '') {
                    app(AgentWorkspaceApplicationService::class)->confirmExecution($context, $this->pendingExecutionRef, $this->confirmationRef);
                    $this->clearDraftPersist($conversation);
                    $this->conversationFlowState = ConversationalAgentFlowService::STATE_COMPLETED;
                    $this->refreshMessages($conversation);

                    return;
                }

                // No-confirm path: reuse execution ref via _execution_ref.
                $formInput = is_array($this->skillForm) ? $this->skillForm : [];
                $formInput['_execution_ref'] = $this->pendingExecutionRef;

                app(AgentWorkspaceApplicationService::class)->execute(
                    $context,
                    $conversation,
                    (string) $this->activeSkillKey,
                    $formInput,
                    confirmationToken: null,
                );

                $this->clearDraftPersist($conversation);
                $this->conversationFlowState = ConversationalAgentFlowService::STATE_EXECUTING;
                $this->refreshMessages($conversation);

                return;
            }

            if ($this->conversationFlowState === ConversationalAgentFlowService::STATE_AWAITING_INPUT) {
                if ($this->editFieldChoiceMode || $this->currentInputKey === null || $this->currentInputKey === '') {
                    $fieldKey = $value;
                    $this->editFieldChoiceMode = false;
                    $this->currentInputKey = $fieldKey;

                    $field = $this->findFieldSchema($this->skillFormSchema, $fieldKey);
                    $flow = new ConversationalAgentFlowService();
                    $q = $flow->buildFieldQuestion($field);

                    app(AgentConversationService::class)->appendMessage(
                        $conversation,
                        role: 'assistant',
                        messageType: 'agent_question',
                        content: (string) ($q['content'] ?? ''),
                        structured: [
                            'input_key' => $fieldKey,
                            'quick_replies' => is_array($q['quickReplies'] ?? null) ? $q['quickReplies'] : [],
                        ],
                        createdBy: $context->actorUserId,
                    );

                    $this->persistActiveDraft($conversation, source: 'edit_field', command: null);
                    $this->refreshMessages($conversation);

                    return;
                }

                $flow = new ConversationalAgentFlowService();
                $field = $this->findFieldSchema($this->skillFormSchema, $this->currentInputKey);
                $parsed = $flow->parseFieldValue($field, $value);
                if (! ($parsed['ok'] ?? false)) {
                    // Re-ask current field.
                    $q = $flow->buildFieldQuestion($field);
                    app(AgentConversationService::class)->appendMessage(
                        $conversation,
                        role: 'assistant',
                        messageType: 'agent_clarification',
                        content: (string) ($parsed['error'] ?? 'Giá trị không hợp lệ.'),
                        structured: [
                            'input_key' => $this->currentInputKey,
                            'quick_replies' => is_array($q['quickReplies'] ?? null) ? $q['quickReplies'] : [],
                        ],
                        createdBy: $context->actorUserId,
                    );

                    $this->refreshMessages($conversation);
                    return;
                }

                $key = $this->currentInputKey;
                $this->skillForm[$key] = $parsed['value'];

                $this->missingInputKeys = array_values(array_filter(
                    $this->missingInputKeys,
                    static fn (string $k): bool => $k !== $key,
                ));

                if ($this->missingInputKeys === []) {
                    $this->conversationFlowState = ConversationalAgentFlowService::STATE_READY_FOR_PREVIEW;
                    $this->currentInputKey = null;

                    $preview = app(AgentWorkspaceApplicationService::class)->preview(
                        $context,
                        $conversation,
                        (string) $this->activeSkillKey,
                        $this->skillForm,
                    );

                    $this->pendingExecutionRef = isset($preview['execution_ref']) && is_string($preview['execution_ref'])
                        ? $preview['execution_ref']
                        : null;

                    $this->confirmationRef = null;
                    if ($this->pendingExecutionRef !== null) {
                        $exec = SeoAgentExecution::query()
                            ->where('public_ref', $this->pendingExecutionRef)
                            ->where('conversation_id', $conversation->id)
                            ->first();
                        $this->confirmationRef = $exec?->confirmation_token_hash;
                    }

                    $this->conversationFlowState = ConversationalAgentFlowService::STATE_AWAITING_CONFIRMATION;
                    $this->persistActiveDraft($conversation, source: 'preview', command: null);
                    $this->refreshMessages($conversation);

                    return;
                }

                $this->currentInputKey = (string) ($this->missingInputKeys[0] ?? null);
                $field = $this->findFieldSchema($this->skillFormSchema, $this->currentInputKey);
                $q = $flow->buildFieldQuestion($field);

                app(AgentConversationService::class)->appendMessage(
                    $conversation,
                    role: 'assistant',
                    messageType: 'agent_question',
                    content: (string) ($q['content'] ?? ''),
                    structured: [
                        'input_key' => $this->currentInputKey,
                        'quick_replies' => is_array($q['quickReplies'] ?? null) ? $q['quickReplies'] : [],
                    ],
                    createdBy: $context->actorUserId,
                );

                $this->persistActiveDraft($conversation, source: 'next_field', command: null);
                $this->refreshMessages($conversation);

                return;
            }
        } finally {
            $this->draftBusy = false;
        }
    }

    /**
     * Load active draft fields from context_summary when page refreshed.
     */
    private function hydrateActiveDraftFromConversation(SeoAgentConversation $conversation): void
    {
        $summary = is_array($conversation->context_summary) ? $conversation->context_summary : [];
        $draft = $summary[self::DRAFT_CONTEXT_KEY] ?? null;
        if (! is_array($draft)) {
            return;
        }

        $expiresAt = $draft['expires_at'] ?? null;
        if (! is_string($expiresAt) || $expiresAt === '') {
            return;
        }

        try {
            $dt = \Carbon\Carbon::parse($expiresAt);
            if ($dt->isPast()) {
                $this->conversationFlowState = ConversationalAgentFlowService::STATE_EXPIRED;
                return;
            }
        } catch (Throwable) {
            return;
        }

        $this->draftRef = is_string($draft['draft_ref'] ?? null) ? $draft['draft_ref'] : null;
        $this->activeSkillKey = is_string($draft['skill_key'] ?? null) ? $draft['skill_key'] : null;
        $this->conversationFlowState = is_string($draft['status'] ?? null)
            ? $draft['status']
            : ConversationalAgentFlowService::STATE_IDLE;
        $this->missingInputKeys = is_array($draft['missing_input_keys'] ?? null) ? $draft['missing_input_keys'] : [];
        $this->currentInputKey = is_string($draft['current_input_key'] ?? null) ? $draft['current_input_key'] : null;
        $this->pendingExecutionRef = is_string($draft['execution_ref'] ?? null) ? $draft['execution_ref'] : null;
        $this->confirmationRef = is_string($draft['confirmation_ref'] ?? null) ? $draft['confirmation_ref'] : null;

        $this->skillForm = is_array($draft['collected_inputs'] ?? null) ? $draft['collected_inputs'] : [];
        $this->editFieldChoiceMode = false;

        // Re-resolve schema from skill key so parsing/questions stay deterministic.
        if ($this->activeSkillKey !== null) {
            try {
                $context = $this->requireContext();
                $opened = app(AgentWorkspaceApplicationService::class)->openSkill(
                    $context,
                    (string) $this->activeSkillKey,
                    is_array($this->skillForm) ? $this->skillForm : [],
                );
                $this->skillForm = $opened['prefill'];
                $this->skillFormSchema = $opened['form'];
                $this->skillMeta = $opened['skill'];
                $this->skillAvailability = $opened['availability'];
            } catch (Throwable) {
                // If re-open fails, keep draft state but disable further actions.
                $this->conversationFlowState = ConversationalAgentFlowService::STATE_FAILED;
            }
        }
    }

    /**
     * Persist draft into conversation->context_summary.
     */
    private function persistActiveDraft(SeoAgentConversation $conversation, string $source, ?string $command): void
    {
        $ctx = is_array($conversation->context_summary) ? $conversation->context_summary : [];

        $expiresAt = now()->addMinutes(15)->toIso8601String();

        $ctx[self::DRAFT_CONTEXT_KEY] = [
            'draft_ref' => $this->draftRef,
            'conversation_id' => $conversation->id,
            'actor_user_id' => $this->requireContext()->actorUserId,
            'connection_hash' => (string) request()->route('connection_hash'),
            'source' => $source,
            'skill_key' => $this->activeSkillKey,
            'command' => $command,
            'status' => $this->conversationFlowState,
            'collected_inputs' => is_array($this->skillForm) ? $this->skillForm : [],
            'missing_input_keys' => $this->missingInputKeys,
            'current_input_key' => $this->currentInputKey,
            'preview_ref' => null,
            'execution_ref' => $this->pendingExecutionRef,
            'confirmation_ref' => $this->confirmationRef,
            'expires_at' => $expiresAt,
        ];

        $conversation->context_summary = $ctx;
        $conversation->save();
    }

    /**
     * Remove draft context from persistence.
     */
    private function clearDraftPersist(SeoAgentConversation $conversation): void
    {
        $ctx = is_array($conversation->context_summary) ? $conversation->context_summary : [];
        unset($ctx[self::DRAFT_CONTEXT_KEY]);
        $conversation->context_summary = $ctx;
        $conversation->save();
    }

    /**
     * Single UI entry for Recommended Skills, slash palette, and structured message actions.
     * Browser sends skill key (or slash) only — server resolves from registry. Does not execute.
     */
    public function selectSkill(string $skillKey): void
    {
        $raw = $this->normalizeAgentReference($skillKey);
        if ($raw === '') {
            Notification::make()
                ->title(__('seo-content-ai::filament.agent_workspace.skill_invalid'))
                ->warning()
                ->send();

            return;
        }

        try {
            $registry = app(AgentSkillRegistry::class);
            $skill = $registry->get($raw) ?? $registry->resolveSlashCommand($raw);
            if ($skill === null) {
                Notification::make()
                    ->title(__('seo-content-ai::filament.agent_workspace.skill_invalid'))
                    ->body($raw)
                    ->warning()
                    ->send();

                return;
            }

            $context = $this->requireContext();
            $conversation = $this->requireConversation($context);
            $flow = new ConversationalAgentFlowService();

            $source = 'skill';
            $commandText = trim($skill->slashCommand);
            if ($commandText === '') {
                $commandText = '/'.$skill->key;
            }

            // Start new draft.
            $this->draftBusy = false;
            $this->editFieldChoiceMode = false;
            $this->conversationFlowState = ConversationalAgentFlowService::STATE_RESOLVING;
            $this->missingInputKeys = [];
            $this->currentInputKey = null;
            $this->draftRef = 'ad_'.Str::lower((string) Str::ulid());
            $this->confirmationRef = null;
            $this->pendingExecutionRef = null;
            $this->previewPayload = null;

            $prefillOverrides = is_array($this->skillForm) ? $this->skillForm : [];
            $opened = app(AgentWorkspaceApplicationService::class)->openSkill(
                $context,
                $skill->key,
                $prefillOverrides,
            );

            $this->activeSkillKey = $skill->key;
            $this->skillForm = $opened['prefill'];
            $this->skillFormSchema = $opened['form'];
            $this->skillMeta = $opened['skill'];
            $this->skillAvailability = $opened['availability'];
            $this->showPalette = false;
            $this->activePanel = 'chat';

            // Append user command into timeline (chat-only UX).
            if (! $this->suppressUserCommandAppend) {
                app(AgentConversationService::class)->appendMessage(
                    $conversation,
                    role: 'user',
                    messageType: 'user_command',
                    content: $commandText,
                    createdBy: $context->actorUserId,
                );
            }
            $this->suppressUserCommandAppend = false;

            if (! ($this->skillAvailability['usable'] ?? false)) {
                $reason = (string) ($this->skillAvailability['reason'] ?? 'Skill chưa sẵn sàng');
                app(AgentConversationService::class)->appendMessage(
                    $conversation,
                    role: 'assistant',
                    messageType: 'agent_error',
                    content: 'Skill không sẵn sàng: '.$reason,
                    createdBy: $context->actorUserId,
                );

                // Clear draft from persistence.
                $ctx = is_array($conversation->context_summary) ? $conversation->context_summary : [];
                unset($ctx[self::DRAFT_CONTEXT_KEY]);
                $conversation->context_summary = $ctx;
                $conversation->save();

                $this->refreshMessages($conversation);

                return;
            }

            $missing = $flow->computeMissingRequiredFields($this->skillFormSchema, $this->skillForm);
            $this->missingInputKeys = $missing;

            $expiresAt = now()->addMinutes(15)->toIso8601String();

            if ($missing !== []) {
                $this->conversationFlowState = ConversationalAgentFlowService::STATE_AWAITING_INPUT;
                $this->currentInputKey = (string) $missing[0];

                $field = $this->findFieldSchema($this->skillFormSchema, $this->currentInputKey);
                $q = $flow->buildFieldQuestion($field);

                app(AgentConversationService::class)->appendMessage(
                    $conversation,
                    role: 'assistant',
                    messageType: 'agent_question',
                    content: (string) ($q['content'] ?? ''),
                    structured: [
                        'input_key' => $this->currentInputKey,
                        'quick_replies' => is_array($q['quickReplies'] ?? null) ? $q['quickReplies'] : [],
                    ],
                    createdBy: $context->actorUserId,
                );

                $ctx = is_array($conversation->context_summary) ? $conversation->context_summary : [];
                $ctx[self::DRAFT_CONTEXT_KEY] = [
                    'draft_ref' => $this->draftRef,
                    'conversation_id' => $conversation->id,
                    'actor_user_id' => $context->actorUserId,
                    'connection_hash' => (string) request()->route('connection_hash'),
                    'source' => $source,
                    'skill_key' => $skill->key,
                    'command' => $commandText,
                    'status' => $this->conversationFlowState,
                    'collected_inputs' => $this->skillForm,
                    'missing_input_keys' => $this->missingInputKeys,
                    'current_input_key' => $this->currentInputKey,
                    'preview_ref' => null,
                    'execution_ref' => null,
                    'confirmation_ref' => null,
                    'expires_at' => $expiresAt,
                ];
                $conversation->context_summary = $ctx;
                $conversation->save();

                $this->refreshMessages($conversation);

                return;
            }

            $this->conversationFlowState = ConversationalAgentFlowService::STATE_READY_FOR_PREVIEW;

            $preview = app(AgentWorkspaceApplicationService::class)->preview(
                $context,
                $conversation,
                $skill->key,
                $this->skillForm,
            );

            $this->pendingExecutionRef = isset($preview['execution_ref']) && is_string($preview['execution_ref'])
                ? $preview['execution_ref']
                : null;

            $this->confirmationRef = null;
            if ($this->pendingExecutionRef !== null) {
                $exec = SeoAgentExecution::query()
                    ->where('public_ref', $this->pendingExecutionRef)
                    ->where('conversation_id', $conversation->id)
                    ->first();
                $this->confirmationRef = $exec?->confirmation_token_hash;
            }

            $this->conversationFlowState = ConversationalAgentFlowService::STATE_AWAITING_CONFIRMATION;

            $ctx = is_array($conversation->context_summary) ? $conversation->context_summary : [];
            $ctx[self::DRAFT_CONTEXT_KEY] = [
                'draft_ref' => $this->draftRef,
                'conversation_id' => $conversation->id,
                'actor_user_id' => $context->actorUserId,
                'connection_hash' => (string) request()->route('connection_hash'),
                'source' => $source,
                'skill_key' => $skill->key,
                'command' => $commandText,
                'status' => $this->conversationFlowState,
                'collected_inputs' => $this->skillForm,
                'missing_input_keys' => [],
                'current_input_key' => null,
                'preview_ref' => null,
                'execution_ref' => $this->pendingExecutionRef,
                'confirmation_ref' => $this->confirmationRef,
                'expires_at' => $expiresAt,
            ];
            $conversation->context_summary = $ctx;
            $conversation->save();

            $this->refreshMessages($conversation);
        } catch (Throwable $e) {
            RuntimeLogger::report($e, ['page' => 'agent-workspace', 'action' => 'selectSkill', 'skill' => $raw]);
            Notification::make()
                ->title(__('seo-content-ai::filament.agent_workspace.skill_open_failed'))
                ->body(__('seo-content-ai::filament.agent_workspace.error_generic'))
                ->danger()
                ->send();
        }
    }

    /**
     * Slash / command palette entry — same as selectSkill (registry resolves slash or key).
     * Browser sends opaque command key via $el.value only.
     */
    public function selectCommand(string $command): void
    {
        $normalized = AgentCliCommandCatalog::normalizeCommand($command);
        if ($normalized !== '' && AgentCliCommandCatalog::get($normalized) !== null) {
            $this->selectCliCommand($normalized);

            return;
        }

        $this->selectSkill($command);
    }

    public function selectCliCommand(string $command): void
    {
        $definition = AgentCliCommandCatalog::get($command);
        if ($definition === null) {
            $this->selectSkill($command);

            return;
        }

        $this->activeCliCommand = $definition['command'];
        $this->cliHelpPanel = [
            'command' => $definition['command'],
            'description' => $definition['description'],
            'example' => $definition['example'],
        ];
        $this->composerText = AgentCliCommandCatalog::buildTemplate($definition);
        $this->showPalette = false;
        $this->dispatch('agent-focus-composer');
        $this->dispatch('agent-cli-template-ready');
    }

    public function getCliArgumentSuggestions(string $argType, string $query = ''): void
    {
        try {
            $context = $this->requireContext();
            $this->cliArgumentSuggestions = app(AgentCliArgumentSuggestService::class)
                ->suggest($argType, $context, $query);
        } catch (Throwable) {
            $this->cliArgumentSuggestions = [];
        }
    }

    /**
     * Chat template cards → resolve template → selectSkill. Does not execute.
     */
    public function selectTemplate(string $templateKey): void
    {
        $raw = $this->normalizeAgentReference($templateKey);
        if ($raw === '') {
            Notification::make()
                ->title(__('seo-content-ai::filament.agent_workspace.template_invalid'))
                ->warning()
                ->send();

            return;
        }

        try {
            $template = app(AgentChatTemplateRegistry::class)->get($raw);
            if ($template === null) {
                Notification::make()
                    ->title(__('seo-content-ai::filament.agent_workspace.template_invalid'))
                    ->body($raw)
                    ->warning()
                    ->send();

                return;
            }

            $this->activeTemplateKey = $template->key;

            if ($template->skillKey !== null) {
                $this->selectSkill($template->skillKey);

                return;
            }

            if (trim($this->composerText) === '') {
                $this->composerText = $template->promptTemplate;
                $this->dispatch('agent-focus-composer');
            }
        } catch (Throwable $e) {
            RuntimeLogger::report($e, ['page' => 'agent-workspace', 'action' => 'selectTemplate', 'template' => $raw]);
            Notification::make()
                ->title(__('seo-content-ai::filament.agent_workspace.template_open_failed'))
                ->body(__('seo-content-ai::filament.agent_workspace.error_generic'))
                ->danger()
                ->send();
        }
    }

    /** @deprecated Use selectSkill — kept for Livewire payload compatibility. */
    public function openSkill(string $skillKey): void
    {
        $this->selectSkill($skillKey);
    }

    /** @deprecated Use selectTemplate — kept for Livewire payload compatibility. */
    public function openTemplate(string $templateKey): void
    {
        $this->selectTemplate($templateKey);
    }

    public function clearSkillSelection(): void
    {
        $this->activeSkillKey = null;
        $this->activeTemplateKey = null;
        $this->skillForm = [];
        $this->skillFormSchema = [];
        $this->skillMeta = null;
        $this->skillAvailability = [];
        $this->previewPayload = null;
        $this->pendingExecutionRef = null;
        $this->pendingConfirmationToken = null;
        $this->missingInputKeys = [];
        $this->currentInputKey = null;
        $this->confirmationRef = null;
        $this->draftRef = null;
        $this->draftBusy = false;
        $this->editFieldChoiceMode = false;
        $this->conversationFlowState = ConversationalAgentFlowService::STATE_IDLE;
        $this->activePanel = 'chat';
    }

    public function previewSkill(): void
    {
        if (! ($this->skillAvailability['usable'] ?? true)) {
            Notification::make()
                ->title((string) ($this->skillMeta['name'] ?? $this->activeSkillKey ?? 'Skill'))
                ->body((string) ($this->skillAvailability['reason'] ?? 'Skill chưa sẵn sàng'))
                ->warning()
                ->send();

            return;
        }

        $context = $this->requireContext();
        $conversation = $this->requireConversation($context);
        if ($this->activeSkillKey === null) {
            return;
        }

        $result = app(AgentWorkspaceApplicationService::class)->preview(
            $context,
            $conversation,
            $this->activeSkillKey,
            $this->skillForm,
        );
        $this->previewPayload = $result;
        $this->pendingExecutionRef = isset($result['execution_ref']) && is_string($result['execution_ref'])
            ? $result['execution_ref']
            : null;
        $this->pendingConfirmationToken = isset($result['confirmation_token']) && is_string($result['confirmation_token'])
            ? $result['confirmation_token']
            : null;
        $this->refreshMessages($conversation);
    }

    public function confirmSkill(?string $confirmationToken = null): void
    {
        if (! ($this->skillAvailability['usable'] ?? true)) {
            Notification::make()
                ->title((string) ($this->skillMeta['name'] ?? $this->activeSkillKey ?? 'Skill'))
                ->body((string) ($this->skillAvailability['reason'] ?? 'Skill chưa sẵn sàng'))
                ->warning()
                ->send();

            return;
        }

        $context = $this->requireContext();
        $conversation = $this->requireConversation($context);
        if ($this->activeSkillKey === null) {
            return;
        }

        $app = app(AgentWorkspaceApplicationService::class);
        $token = $confirmationToken
            ?? $this->pendingConfirmationToken
            ?? (is_array($this->previewPayload) ? ($this->previewPayload['confirmation_token'] ?? null) : null);

        if (is_string($token) && $token !== '' && is_string($this->pendingExecutionRef) && $this->pendingExecutionRef !== '') {
            $result = $app->confirmExecution($context, $this->pendingExecutionRef, $token);
        } else {
            // Read / no-confirmation path — never invent a confirmation token.
            $result = $app->execute(
                $context,
                $conversation,
                $this->activeSkillKey,
                $this->skillForm,
                null,
            );
        }

        if (($result['ok'] ?? false) === true) {
            Notification::make()
                ->title(__('seo-content-ai::filament.agent_workspace.execute_ok'))
                ->success()
                ->send();
            $this->clearSkillSelection();
        } elseif (($result['code'] ?? '') === 'confirmation_required') {
            $this->previewPayload = $result['meta']['preview'] ?? $result;
            $this->pendingExecutionRef = isset($result['execution_ref']) ? (string) $result['execution_ref'] : $this->pendingExecutionRef;
            if (isset($result['meta']['preview']['confirmation_token']) && is_string($result['meta']['preview']['confirmation_token'])) {
                $this->pendingConfirmationToken = $result['meta']['preview']['confirmation_token'];
            }
            Notification::make()
                ->title(__('seo-content-ai::filament.agent_workspace.confirm_required'))
                ->warning()
                ->send();
        } else {
            Notification::make()
                ->title(__('seo-content-ai::filament.agent_workspace.execute_failed'))
                ->body((string) ($result['message'] ?? ''))
                ->danger()
                ->send();
        }

        $this->refreshMessages($conversation);
    }

    public function confirmPendingExecution(): void
    {
        $this->confirmSkill($this->pendingConfirmationToken);
    }

    public function cancelPendingExecution(): void
    {
        if ($this->pendingExecutionRef === null || $this->pendingExecutionRef === '') {
            $this->clearSkillSelection();

            return;
        }

        $context = $this->requireContext();
        $conversation = $this->requireConversation($context);
        app(AgentWorkspaceApplicationService::class)->cancelExecution($context, $this->pendingExecutionRef, 'user_cancel');
        $this->clearSkillSelection();
        $this->refreshMessages($conversation);
    }

    public function retryExecution(string $executionRef): void
    {
        $context = $this->requireContext();
        $conversation = $this->requireConversation($context);
        $result = app(AgentWorkspaceApplicationService::class)->retryExecution($context, $executionRef);
        if (($result['code'] ?? '') === 'confirmation_required') {
            $this->pendingExecutionRef = (string) ($result['execution_ref'] ?? '');
            $preview = is_array($result['meta']['preview'] ?? null) ? $result['meta']['preview'] : [];
            $this->previewPayload = $preview;
            $this->pendingConfirmationToken = isset($preview['confirmation_token']) && is_string($preview['confirmation_token'])
                ? $preview['confirmation_token']
                : null;
            if (isset($preview['skill_key']) && is_string($preview['skill_key'])) {
                $this->selectSkill($preview['skill_key']);
            }
        }
        $this->refreshMessages($conversation);
    }

    public function pollExecution(string $executionRef): void
    {
        // Refresh conversation messages — status cards read from message structured payload.
        // No business queue table queries from UI.
        if ($executionRef === '') {
            return;
        }
        $this->refreshConversationState();
    }

    public function sendMessage(?string $message = null): void
    {
        if (is_string($message)) {
            $normalized = trim($message);
            if ($normalized !== '') {
                // Cap composer payload length from browser.
                if (strlen($normalized) > 8000) {
                    $normalized = substr($normalized, 0, 8000);
                }
                $this->composerText = $normalized;
            }
        }

        $this->submitComposer();
    }

    public function pollActiveExecutions(): void
    {
        foreach ($this->messages as $message) {
            if (! is_array($message)) {
                continue;
            }
            $structured = is_array($message['structured_content'] ?? null) ? $message['structured_content'] : [];
            $status = (string) ($structured['status'] ?? '');
            $ref = (string) ($structured['execution_ref'] ?? '');
            if ($ref !== '' && in_array($status, ['queued', 'running'], true)) {
                $this->pollExecution($ref);
            }
        }
    }

    public function submitComposer(): void
    {
        if ($this->composerSubmitting) {
            return;
        }

        $text = trim($this->composerText);
        if ($text === '') {
            return;
        }

        $this->composerSubmitting = true;
        $this->composerError = '';
        $messageAccepted = false;
        $context = null;
        $conversation = null;

        try {
            $context = $this->requireContext();
            $conversation = $this->requireConversation($context);
            $conversations = app(AgentConversationService::class);

            $conversations->appendMessage(
                $conversation,
                role: 'user',
                messageType: 'text',
                content: $text,
                createdBy: $context->actorUserId,
            );
            $messageAccepted = true;
            $this->composerText = '';
            $this->showPalette = false;
            $this->refreshMessages($conversation);

            // Draft composer path: next user message answers the active draft (chat-only CLI UX).
            if ($this->conversationFlowState === ConversationalAgentFlowService::STATE_AWAITING_INPUT
                || $this->conversationFlowState === ConversationalAgentFlowService::STATE_AWAITING_CONFIRMATION
                || $this->editFieldChoiceMode
            ) {
                $this->answerConversation($text);

                return;
            }

            // Static CLI commands (UX catalog) — before general intent router.
            if (str_starts_with(ltrim($text), '/')) {
                if ($this->tryHandleCliComposer($text, $context, $conversation)) {
                    return;
                }
            }

            // Memory proposals — never auto-persist.
            if (! str_starts_with($text, '/')) {
                $this->maybeOfferMemoryProposals($context, $conversation, $text);
            }

            $resolution = app(AgentIntentRouter::class)->resolve($text, [
                'hints' => [
                    'project_ref' => $context->projectRef,
                    'workspace_ref' => $context->workspaceRef,
                    'site_ref' => $context->siteRef,
                ],
            ]);

            // Copilot only when IntentRouter falls through to assistant (no slash/template/deterministic).
            if ($resolution->source === AgentIntentResolution::SOURCE_ASSISTANT) {
                $this->runNaturalLanguagePlanning($context, $conversation, $text);
                $this->dispatch('agent-focus-composer');

                return;
            }

            if ($resolution->requiresUserChoice && $resolution->planSteps !== null) {
                $conversations->appendMessage(
                    $conversation,
                    role: 'assistant',
                    messageType: 'notice',
                    content: $resolution->message,
                    structured: ['plan_steps' => $resolution->planSteps],
                    createdBy: $context->actorUserId,
                );
                $this->refreshMessages($conversation);
                $this->dispatch('agent-focus-composer');

                return;
            }

            if ($resolution->requiresUserChoice) {
                $choices = [];
                foreach ($resolution->candidateSkillKeys as $key) {
                    $skill = app(AgentSkillRegistry::class)->get($key);
                    if ($skill !== null) {
                        $choices[] = ['skill_key' => $skill->key, 'name' => $skill->name];
                    }
                }
                $conversations->appendMessage(
                    $conversation,
                    role: 'assistant',
                    messageType: 'notice',
                    content: $resolution->message !== '' ? $resolution->message : 'Bạn muốn làm việc nào?',
                    structured: ['choices' => $choices],
                    createdBy: $context->actorUserId,
                );
                $this->refreshMessages($conversation);
                $this->dispatch('agent-focus-composer');

                return;
            }

            if ($resolution->skillKey !== null) {
                // Feed extracted inputs into selectSkill prefill so the draft questions stay deterministic.
                $prefill = [];
                foreach ($resolution->extractedInputs as $k => $v) {
                    if (is_string($k)) {
                        $prefill[$k] = $v;
                    }
                }
                $this->skillForm = $prefill;

                // Composer already appended user's message into timeline, so suppress duplicating user_command.
                $this->suppressUserCommandAppend = true;
                $this->selectSkill($resolution->skillKey);

                return;
            }

            $conversations->appendMessage(
                $conversation,
                role: 'assistant',
                messageType: 'text',
                content: 'Mình chưa map được yêu cầu sang kỹ năng cụ thể. Gõ / để xem danh sách, hoặc chọn một card gợi ý.',
                createdBy: $context->actorUserId,
            );
            $this->refreshMessages($conversation);
            $this->dispatch('agent-focus-composer');
        } catch (Throwable $e) {
            RuntimeLogger::report($e, ['page' => 'agent-workspace', 'action' => 'sendMessage']);
            if (! $messageAccepted) {
                // Keep composer text when request was not accepted.
            }
            $this->composerError = $this->safeComposerErrorMessage($e);
            Notification::make()
                ->title(__('seo-content-ai::filament.agent_workspace.send_failed'))
                ->body($this->composerError)
                ->danger()
                ->send();

            try {
                if ($conversation instanceof SeoAgentConversation && $context instanceof AgentWorkspaceContext) {
                    app(AgentConversationService::class)->appendMessage(
                        $conversation,
                        role: 'assistant',
                        messageType: 'execution_error',
                        content: $this->composerError,
                        structured: ['card' => 'error', 'code' => 'composer_error'],
                        createdBy: $context->actorUserId,
                    );
                    $this->refreshMessages($conversation);
                }
            } catch (Throwable) {
                // ignore secondary failure
            }
        } finally {
            $this->composerSubmitting = false;
        }
    }

    public function openSkillBrowser(): void
    {
        $this->paletteQuery = '';
        $this->refreshPalette();
        $this->showPalette = false;
    }

    private function safeComposerErrorMessage(Throwable $e): string
    {
        $raw = strtolower($e->getMessage());
        if (str_contains($raw, 'csrf') || str_contains($raw, '419') || str_contains($raw, 'page expired')) {
            return __('seo-content-ai::filament.agent_workspace.error_session');
        }
        if (str_contains($raw, '403') || str_contains($raw, 'forbidden') || str_contains($raw, 'unauthorized') || str_contains($raw, '401')) {
            return __('seo-content-ai::filament.agent_workspace.error_forbidden');
        }
        if (str_contains($raw, '429') || str_contains($raw, 'rate')) {
            return __('seo-content-ai::filament.agent_workspace.error_rate_limit');
        }

        return __('seo-content-ai::filament.agent_workspace.error_generic');
    }

    /**
     * @param  array<string, mixed>  $answers
     */
    public function submitClarification(array $answers = []): void
    {
        $context = $this->requireContext();
        $conversation = $this->requireConversation($context);
        $merged = $answers !== [] ? $answers : $this->clarificationAnswers;
        $userMessage = 'Clarification answers: '.(string) json_encode($merged, JSON_UNESCAPED_UNICODE);

        app(AgentConversationService::class)->appendMessage(
            $conversation,
            role: 'user',
            messageType: 'text',
            content: $userMessage,
            structured: ['clarification_answers' => $merged],
            createdBy: $context->actorUserId,
        );

        $result = app(AgentWorkspaceApplicationService::class)->answerClarification(
            $context,
            $conversation,
            $userMessage,
            is_array($merged) ? $merged : [],
        );

        $this->clarificationAnswers = [];
        $this->renderPlanningResult($conversation, $context, $result);
    }

    public function saveProposedPlan(): void
    {
        if ($this->proposedPlan === null) {
            return;
        }
        $context = $this->requireContext();
        $conversation = $this->requireConversation($context);
        $result = app(AgentWorkspaceApplicationService::class)->saveProposedPlan(
            $context,
            $conversation,
            $this->proposedPlan,
        );

        app(AgentConversationService::class)->appendMessage(
            $conversation,
            role: 'assistant',
            messageType: 'execution_plan',
            content: (string) ($result['message'] ?? 'Plan saved.'),
            structured: [
                'plan_ref' => $result['plan_ref'] ?? null,
                'executed' => false,
                'run_all' => false,
                'ok' => (bool) ($result['ok'] ?? false),
            ],
            createdBy: $context->actorUserId,
        );

        if (($result['ok'] ?? false) === true) {
            Notification::make()->title('Đã lưu kế hoạch — chưa chạy bước nào.')->success()->send();
        } else {
            Notification::make()->title((string) ($result['message'] ?? 'Không lưu được plan.'))->danger()->send();
        }

        $this->proposedPlan = null;
        $this->refreshMessages($conversation);
    }

    public function cancelProposedPlan(): void
    {
        $this->proposedPlan = null;
        $this->clarificationAnswers = [];
        Notification::make()->title('Đã hủy đề xuất.')->success()->send();
    }

    public function openProposedIntentForm(string $skillKey): void
    {
        $this->selectSkill($skillKey);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function renderPlanningResult(
        SeoAgentConversation $conversation,
        AgentWorkspaceContext $context,
        array $result,
    ): void {
        $ui = is_array($result['ui'] ?? null) ? $result['ui'] : [];
        $card = (string) ($ui['card'] ?? 'unsupported');
        $response = is_array($result['response'] ?? null) ? $result['response'] : [];
        $this->lastPlanningDiagnostics = is_array($result['diagnostics'] ?? null)
            ? $result['diagnostics']
            : [];
        $this->suggestedActions = is_array($result['suggested_actions'] ?? null)
            ? $result['suggested_actions']
            : [];

        if ($card === 'proposed_plan' && isset($response['plan']) && is_array($response['plan'])) {
            $this->proposedPlan = $response['plan'];
        }

        if ($card === 'proposed_intent' && isset($ui['skill_key']) && is_string($ui['skill_key'])) {
            foreach (is_array($ui['input'] ?? null) ? $ui['input'] : [] as $k => $v) {
                if (is_string($k)) {
                    $this->skillForm[$k] = $v;
                }
            }
        }

        $messageType = match ($card) {
            'proposed_intent' => 'proposed_intent',
            'proposed_plan' => 'proposed_plan',
            'clarification' => 'clarification',
            'assistant_answer' => 'assistant_answer',
            default => 'unsupported',
        };

        app(AgentConversationService::class)->appendMessage(
            $conversation,
            role: 'assistant',
            messageType: $messageType,
            content: (string) ($result['message'] ?? $ui['summary'] ?? ''),
            structured: array_merge($ui, [
                'response' => $response,
                'planning_request_id' => $result['planning_request_id'] ?? null,
                'uncertain' => (bool) ($result['uncertain'] ?? false),
            ]),
            createdBy: $context->actorUserId,
        );

        $this->refreshMessages($conversation);
    }

    private function maybeOfferMemoryProposals(
        AgentWorkspaceContext $context,
        SeoAgentConversation $conversation,
        string $text,
    ): void {
        $app = app(AgentWorkspaceApplicationService::class);
        $candidates = $app->extractMemoryCandidates($context, $text);
        foreach (array_slice($candidates, 0, 2) as $candidate) {
            $created = $app->proposeMemory($context, $conversation, $candidate);
            if (! ($created['ok'] ?? false)) {
                continue;
            }
            app(AgentConversationService::class)->appendMessage(
                $conversation,
                role: 'assistant',
                messageType: 'memory_proposal',
                content: 'Có thể lưu vào Knowledge?',
                structured: [
                    'proposal' => $created['proposal'] ?? [],
                ],
                createdBy: $context->actorUserId,
            );
        }
    }

    private function runNaturalLanguagePlanning(
        AgentWorkspaceContext $context,
        SeoAgentConversation $conversation,
        string $text,
    ): void {
        $conversations = app(AgentConversationService::class);
        $conversations->appendMessage(
            $conversation,
            role: 'assistant',
            messageType: 'planning_status',
            content: 'Đang phân tích yêu cầu…',
            structured: ['status' => 'analyzing'],
            createdBy: $context->actorUserId,
        );
        $this->refreshMessages($conversation);

        try {
            $result = app(AgentWorkspaceApplicationService::class)->planNaturalLanguage(
                $context,
                $conversation,
                $text,
            );
        } catch (Throwable $e) {
            RuntimeLogger::report($e, ['page' => 'agent-workspace', 'action' => 'planNaturalLanguage']);
            $conversations->appendMessage(
                $conversation,
                role: 'assistant',
                messageType: 'unsupported',
                content: 'Không lập được kế hoạch AI. Gõ / để chọn Slash Skill.',
                structured: ['card' => 'unsupported', 'code' => 'internal_error'],
                createdBy: $context->actorUserId,
            );
            $this->refreshMessages($conversation);

            return;
        }

        $this->renderPlanningResult($conversation, $context, $result);
    }

    public function updatedPaletteQuery(): void
    {
        $this->refreshPalette();
    }

    public function updatedComposerText(): void
    {
        $text = $this->composerText;
        if (str_starts_with(ltrim($text), '/')) {
            $this->showPalette = true;
            $query = ltrim(explode(' ', ltrim($text), 2)[0] ?? '/', '/');
            $this->paletteQuery = $query === '' ? '' : $query;
            $this->refreshPalette();
        } else {
            $this->showPalette = false;
        }
    }

    public function loadDiagnostics(): void
    {
        if (! SeoAccessControl::canAccessManagerFeatures()) {
            $this->diagnostics = [];

            return;
        }

        $context = $this->requireContext();
        $this->diagnostics = app(AgentCapabilityDiagnosticsService::class)->list($context);
        $this->activePanel = 'diagnostics';
    }

    public function openKnowledgePanel(): void
    {
        $this->activePanel = 'knowledge';
        $this->refreshKnowledgeList();
    }

    public function openAutomationsPanel(): void
    {
        $this->activePanel = 'automations';
        $this->refreshAutomationsList();
    }

    public function openOperationsPanel(): void
    {
        if (! SeoAccessControl::canAccessManagerFeatures()) {
            $this->operationsOverview = ['ok' => false, 'code' => 'forbidden'];

            return;
        }
        $this->activePanel = 'operations';
        $context = $this->requireContext();
        $app = app(AgentWorkspaceApplicationService::class);
        $this->operationsOverview = $app->operationsOverview($context);
        $this->workspaceVersion = $app->workspaceVersion();
        $this->skillGroups = $app->skillGroups();
    }

    public function runV1ReadinessCheck(): void
    {
        if (! SeoAccessControl::canAccessManagerFeatures()) {
            $this->v1Readiness = ['ok' => false, 'code' => 'forbidden'];

            return;
        }
        $context = $this->requireContext();
        $this->v1Readiness = app(AgentWorkspaceApplicationService::class)->v1Readiness($context, fixSafe: false);
        $this->activePanel = 'operations';
    }

    public function openPacksPanel(): void
    {
        if (! SeoAccessControl::canAccessManagerFeatures()) {
            $this->packItems = [];

            return;
        }
        $this->activePanel = 'packs';
        $this->refreshPacksList();
    }

    public function refreshPacksList(): void
    {
        $context = $this->requireContext();
        $this->packItems = app(AgentWorkspaceApplicationService::class)->listPacks($context);
        $this->packDetail = null;
    }

    public function viewPack(string $hashId): void
    {
        foreach ($this->packItems as $item) {
            if (($item['hash_id'] ?? '') === $hashId) {
                $this->packDetail = $item;
                $this->packStudioTab = 'overview';

                return;
            }
        }
    }

    public function setPackStudioTab(string $tab): void
    {
        $allowed = ['overview', 'skills', 'templates', 'compatibility', 'evaluations', 'revisions', 'diagnostics'];
        if (in_array($tab, $allowed, true)) {
            $this->packStudioTab = $tab;
        }
    }

    public function enablePack(string $hashId): void
    {
        if (! SeoAccessControl::canAccessManagerFeatures()) {
            return;
        }
        $context = $this->requireContext();
        $conversation = $this->requireConversation($context);
        app(AgentWorkspaceApplicationService::class)->execute(
            $context,
            $conversation,
            'packs.enable',
            ['pack_ref' => $hashId, 'explicit_approval' => '1'],
        );
        $this->refreshPacksList();
    }

    public function disablePack(string $hashId): void
    {
        if (! SeoAccessControl::canAccessManagerFeatures()) {
            return;
        }
        $context = $this->requireContext();
        $conversation = $this->requireConversation($context);
        app(AgentWorkspaceApplicationService::class)->execute(
            $context,
            $conversation,
            'packs.disable',
            ['pack_ref' => $hashId],
        );
        $this->refreshPacksList();
    }

    public function refreshAutomationsList(): void
    {
        $context = $this->requireContext();
        $this->automationItems = app(AgentWorkspaceApplicationService::class)->listAutomations($context);
        $this->automationHistory = [];
        $this->automationDetail = null;
        $this->automationDiagnostics = null;
    }

    public function viewAutomation(string $hashId): void
    {
        $context = $this->requireContext();
        $conversation = $this->requireConversation($context);
        $this->automationDetail = app(AgentWorkspaceApplicationService::class)
            ->execute($context, $conversation, 'automation.status', [
                'automation_ref' => $hashId,
            ]);
        $this->automationHistory = app(AgentWorkspaceApplicationService::class)
            ->execute($context, $conversation, 'automation.history', [
                'automation_ref' => $hashId,
            ])['runs'] ?? [];
    }

    public function runAutomationNow(string $hashId): void
    {
        $context = $this->requireContext();
        app(AgentWorkspaceApplicationService::class)->execute(
            $context,
            $this->requireConversation($context),
            'automation.run',
            ['automation_ref' => $hashId],
        );
        $this->refreshAutomationsList();
        Notification::make()->title('Automation queued')->success()->send();
    }

    public function pauseAutomation(string $hashId): void
    {
        $context = $this->requireContext();
        app(AgentWorkspaceApplicationService::class)->execute(
            $context,
            $this->requireConversation($context),
            'automation.pause',
            ['automation_ref' => $hashId],
        );
        $this->refreshAutomationsList();
    }

    public function resumeAutomation(string $hashId): void
    {
        $context = $this->requireContext();
        app(AgentWorkspaceApplicationService::class)->execute(
            $context,
            $this->requireConversation($context),
            'automation.resume',
            ['automation_ref' => $hashId],
        );
        $this->refreshAutomationsList();
    }

    public function deleteAutomation(string $hashId): void
    {
        $context = $this->requireContext();
        app(AgentWorkspaceApplicationService::class)->execute(
            $context,
            $this->requireConversation($context),
            'automation.delete',
            ['automation_ref' => $hashId],
        );
        $this->refreshAutomationsList();
    }

    public function loadAutomationDiagnostics(string $hashId): void
    {
        if (! SeoAccessControl::canAccessManagerFeatures()) {
            $this->automationDiagnostics = null;

            return;
        }
        $context = $this->requireContext();
        $this->automationDiagnostics = app(AgentWorkspaceApplicationService::class)
            ->automationDiagnostics($context, $hashId);
    }

    public function updatedKnowledgeFilters(): void
    {
        if ($this->activePanel === 'knowledge') {
            $this->refreshKnowledgeList();
        }
    }

    public function openChatPanel(): void
    {
        $this->activePanel = 'chat';
    }

    public function refreshKnowledgeList(): void
    {
        $context = $this->requireContext();
        $filters = array_filter(
            $this->knowledgeFilters,
            static fn ($v): bool => is_string($v) && $v !== '',
        );
        $this->knowledgeItems = app(AgentWorkspaceApplicationService::class)->listKnowledge($context, $filters);
    }

    public function clearKnowledgeDetail(): void
    {
        $this->knowledgeDetail = null;
    }

    public function viewKnowledge(string $hashId): void
    {
        $hashId = $this->normalizeAgentReference($hashId);
        if ($hashId === '') {
            return;
        }

        $this->activePanel = 'knowledge';
        $item = null;
        foreach ($this->knowledgeItems as $row) {
            if (($row['hash_id'] ?? '') === $hashId) {
                $item = $row;
                break;
            }
        }
        if ($item === null) {
            $this->refreshKnowledgeList();
            foreach ($this->knowledgeItems as $row) {
                if (($row['hash_id'] ?? '') === $hashId) {
                    $item = $row;
                    break;
                }
            }
        }
        $this->knowledgeDetail = $item;
    }

    public function resolveMemoryProposal(string $proposalId, string $action): void
    {
        $context = $this->requireContext();
        $result = app(AgentWorkspaceApplicationService::class)->resolveMemoryProposal(
            $context,
            $proposalId,
            $action,
        );
        $title = (string) ($result['message'] ?? $result['code'] ?? 'Done');
        if ($result['ok'] ?? false) {
            Notification::make()->title($title)->success()->send();
        } else {
            Notification::make()->title($title)->danger()->send();
        }
        $this->refreshKnowledgeList();
        $this->refreshConversationState();
    }

    public function forgetKnowledge(string $hashId): void
    {
        $context = $this->requireContext();
        $result = app(AgentWorkspaceApplicationService::class)->forgetKnowledge($context, $hashId);
        $title = (string) ($result['message'] ?? ($result['ok'] ?? false ? 'Forgotten' : 'Failed'));
        if ($result['ok'] ?? false) {
            Notification::make()->title($title)->success()->send();
        } else {
            Notification::make()->title($title)->danger()->send();
        }
        $this->knowledgeDetail = null;
        $this->refreshKnowledgeList();
    }

    public function archiveConversation(string $ref): void
    {
        $context = $this->requireContext();
        $conversation = app(AgentConversationService::class)->findForContext($ref, $context);
        if ($conversation === null) {
            return;
        }
        app(AgentConversationService::class)->archive($conversation);
        if ($this->conversationRef === $ref) {
            $this->conversationRef = null;
            $this->messages = [];
        }
        $this->refreshConversationList($context);
    }

    public function pinConversation(string $ref): void
    {
        $context = $this->requireContext();
        $conversation = app(AgentConversationService::class)->findForContext($ref, $context);
        if ($conversation === null) {
            return;
        }
        app(AgentConversationService::class)->pin($conversation, ! $conversation->is_pinned);
        $this->refreshConversationList($context);
    }

    private function bootWorkspace(): void
    {
        /** @var User $user */
        $user = auth()->user();
        $query = [
            'project_ref' => request()->query('project_ref'),
            'workspace_ref' => request()->query('workspace_ref'),
            'article_ref' => request()->query('article_ref'),
            'operation_ref' => request()->query('operation_ref'),
        ];

        $context = app(AgentWorkspaceContextService::class)->fromAuthenticatedUser($user, $query);
        $this->workspaceContext = $context->toSummary();
        $this->refreshConversationList($context);
        $this->refreshSuggestions($context);

        if ($this->conversationRef === null && $this->conversations !== []) {
            $this->conversationRef = (string) ($this->conversations[0]['public_ref'] ?? null);
        }

        if ($this->conversationRef === null) {
            $this->createConversation();
        } else {
            $this->refreshConversationState();
        }
    }

    private function refreshConversationState(): void
    {
        $context = $this->requireContext();
        $this->refreshConversationList($context);
        $conversation = $this->requireConversation($context);
        $this->hydrateActiveDraftFromConversation($conversation);
        $this->hydrateKeywordContext($conversation);
        $this->refreshMessages($conversation);
        $this->refreshSuggestions($context);
        $this->refreshPalette();
    }

    private function refreshConversationList(AgentWorkspaceContext $context): void
    {
        $rows = app(AgentConversationService::class)->listForUser($context);
        $this->conversations = array_map(static function (SeoAgentConversation $row): array {
            return [
                'public_ref' => $row->public_ref,
                'title' => $row->title,
                'is_pinned' => (bool) $row->is_pinned,
                'status' => $row->status,
                'last_message_at' => $row->last_message_at?->toIso8601String(),
                'active_skill_key' => $row->active_skill_key,
            ];
        }, $rows);
    }

    private function refreshMessages(SeoAgentConversation $conversation): void
    {
        $this->messages = SeoAgentMessage::query()
            ->where('conversation_id', $conversation->id)
            ->orderBy('id')
            ->limit(200)
            ->get()
            ->map(static fn (SeoAgentMessage $m): array => [
                'public_ref' => $m->public_ref,
                'role' => $m->role,
                'message_type' => $m->message_type,
                'content' => $m->content,
                'structured_content' => $m->structured_content,
                'skill_key' => $m->skill_key,
                'operation_ref' => $m->operation_ref,
                'created_at' => $m->created_at?->toIso8601String(),
            ])
            ->all();
    }

    private function refreshSuggestions(AgentWorkspaceContext $context): void
    {
        $templates = app(AgentChatTemplateRegistry::class)->featured();
        $this->suggestedActions = array_map(static fn ($t): array => [
            'key' => $t->key,
            'title' => $t->title,
            'description' => $t->description,
            'category' => $t->category,
            'skill_key' => $t->skillKey,
        ], $templates);

        $recommended = app(AgentSkillRecommendationService::class)->recommend($context->toAvailabilityContext());
        $this->recommendedSkills = array_map(static function (array $row): array {
            /** @var \App\Addons\SeoContentAi\Services\AgentWorkspace\Dtos\AgentSkillDefinition $skill */
            $skill = $row['skill'];

            return [
                'key' => $skill->key,
                'slash_command' => $skill->slashCommand,
                'name' => $skill->name,
                'description' => $skill->description,
                'availability' => $row['availability'],
                'reason' => $row['reason'],
            ];
        }, $recommended);
    }

    private function refreshPalette(): void
    {
        $context = $this->workspaceContext !== [] ? $this->requireContext() : null;
        $registry = app(AgentSkillRegistry::class);
        $availability = app(AgentSkillAvailabilityService::class);
        $rows = AgentCliCommandCatalog::search($this->paletteQuery);
        $out = [];

        foreach ($rows as $row) {
            $skillKey = $row['skill_key'] ?? null;
            $avail = ['status' => 'available', 'usable' => true, 'reason' => ''];
            $confirmationPolicy = 'none';

            if (is_string($skillKey) && $skillKey !== '' && $context !== null) {
                $skill = $registry->get($skillKey);
                if ($skill !== null) {
                    $avail = $availability->resolve($skill, $context->toAvailabilityContext())->toArray();
                    $confirmationPolicy = $skill->confirmationPolicy;
                }
            }

            $out[] = [
                'key' => $skillKey ?? $row['command'],
                'command' => $row['command'],
                'slash_command' => $row['command'],
                'name' => $row['command'],
                'description' => $row['description'],
                'example' => $row['example'],
                'category' => $row['category'],
                'confirmation_policy' => $confirmationPolicy,
                'is_coming_soon' => $skillKey === null,
                'availability' => $avail,
            ];
        }

        $this->paletteSkills = $out;
    }

    private function tryHandleCliComposer(string $text, AgentWorkspaceContext $context, SeoAgentConversation $conversation): bool
    {
        $parser = app(AgentCliCommandParser::class);
        $parsed = $parser->parse($text, $this->keywordContext);

        if (! ($parsed['ok'] ?? false)) {
            $error = (string) ($parsed['error'] ?? '');
            if ($error === 'no_keyword_context') {
                app(AgentConversationService::class)->appendMessage(
                    $conversation,
                    role: 'assistant',
                    messageType: 'agent_error',
                    content: "No keyword context found.\nRun /keyword-suggest first or input keywords manually.",
                    createdBy: $context->actorUserId,
                );
                $this->refreshMessages($conversation);

                return true;
            }

            if (str_starts_with($error, 'missing_required:') || str_starts_with($error, 'missing_positional:')) {
                $def = AgentCliCommandCatalog::get(explode(' ', trim($text))[0] ?? '');
                if ($def !== null) {
                    app(AgentConversationService::class)->appendMessage(
                        $conversation,
                        role: 'assistant',
                        messageType: 'agent_clarification',
                        content: "Thiếu tham số bắt buộc.\nExample:\n".$def['example'],
                        createdBy: $context->actorUserId,
                    );
                    $this->refreshMessages($conversation);

                    return true;
                }
            }

            return false;
        }

        if ($parsed['is_meta'] ?? false) {
            $this->handleMetaCliCommand($parsed, $context, $conversation);

            return true;
        }

        $skillKey = (string) ($parsed['skill_key'] ?? '');
        if ($skillKey === '') {
            return false;
        }

        $this->skillForm = is_array($parsed['inputs'] ?? null) ? $parsed['inputs'] : [];
        $this->activeCliCommand = (string) ($parsed['command'] ?? null);
        $this->cliHelpPanel = null;
        $this->suppressUserCommandAppend = true;
        $this->selectSkill($skillKey);

        return true;
    }

    /**
     * @param  array<string, mixed>  $parsed
     */
    private function handleMetaCliCommand(array $parsed, AgentWorkspaceContext $context, SeoAgentConversation $conversation): void
    {
        $command = (string) ($parsed['command'] ?? '');

        if (in_array($command, ['/member-list', '/member-available'], true)) {
            $availableOnly = $command === '/member-available';
            $members = app(AgentCliArgumentSuggestService::class)
                ->suggestMembers($context, '', $availableOnly);

            $lines = ["Members (".count($members).')'];
            foreach ($members as $row) {
                $lines[] = '- '.$row['label'];
            }
            if ($members === []) {
                $lines[] = '(none)';
            }

            app(AgentConversationService::class)->appendMessage(
                $conversation,
                role: 'assistant',
                messageType: 'agent_result',
                content: implode("\n", $lines),
                createdBy: $context->actorUserId,
            );
            $this->refreshMessages($conversation);

            return;
        }

        if (in_array($command, ['/audit-list', '/audit-keyword-suggest'], true)) {
            app(AgentConversationService::class)->appendMessage(
                $conversation,
                role: 'assistant',
                messageType: 'agent_status',
                content: 'SEO Audit chưa có Agent skill riêng. Mở Articles Optimal (SEO Audit) trong panel SEO.',
                createdBy: $context->actorUserId,
            );
            $this->refreshMessages($conversation);

            return;
        }

        $def = AgentCliCommandCatalog::get($command);
        app(AgentConversationService::class)->appendMessage(
            $conversation,
            role: 'assistant',
            messageType: 'agent_status',
            content: ($def['description'] ?? 'Command')."\nExample:\n".($def['example'] ?? ''),
            createdBy: $context->actorUserId,
        );
        $this->refreshMessages($conversation);
    }

    /**
     * @param  list<string>  $keywords
     */
    public function storeKeywordContext(array $keywords): void
    {
        $indexed = [];
        $i = 1;
        foreach ($keywords as $kw) {
            $kw = trim((string) $kw);
            if ($kw === '') {
                continue;
            }
            $indexed[$i] = $kw;
            $i++;
        }
        $this->keywordContext = $indexed;

        try {
            $context = $this->requireContext();
            $conversation = $this->requireConversation($context);
            $summary = is_array($conversation->context_summary) ? $conversation->context_summary : [];
            $summary[self::KEYWORD_CONTEXT_KEY] = [
                'keywords' => $indexed,
                'expires_at' => now()->addHours(2)->toIso8601String(),
            ];
            $conversation->context_summary = $summary;
            $conversation->save();
        } catch (Throwable) {
            // ignore persistence failure
        }
    }

    private function hydrateKeywordContext(SeoAgentConversation $conversation): void
    {
        $summary = is_array($conversation->context_summary) ? $conversation->context_summary : [];
        $ctx = $summary[self::KEYWORD_CONTEXT_KEY] ?? null;
        if (! is_array($ctx)) {
            return;
        }

        $expiresAt = $ctx['expires_at'] ?? null;
        if (is_string($expiresAt) && $expiresAt !== '') {
            try {
                if (\Carbon\Carbon::parse($expiresAt)->isPast()) {
                    return;
                }
            } catch (Throwable) {
                return;
            }
        }

        $keywords = $ctx['keywords'] ?? [];
        if (is_array($keywords)) {
            $this->keywordContext = array_map(static fn ($v): string => (string) $v, $keywords);
        }
    }

    private function requireContext(): AgentWorkspaceContext
    {
        /** @var User $user */
        $user = auth()->user();

        return app(AgentWorkspaceContextService::class)->fromAuthenticatedUser($user, [
            'site_id' => isset($this->workspaceContext['site_id']) ? (int) $this->workspaceContext['site_id'] : null,
            'project_ref' => $this->workspaceContext['project_ref'] ?? request()->query('project_ref'),
            'workspace_ref' => $this->workspaceContext['workspace_ref'] ?? request()->query('workspace_ref'),
            'article_ref' => $this->workspaceContext['article_ref'] ?? request()->query('article_ref'),
            'operation_ref' => $this->workspaceContext['operation_ref'] ?? request()->query('operation_ref'),
        ]);
    }

    private function requireConversation(AgentWorkspaceContext $context): SeoAgentConversation
    {
        if ($this->conversationRef === null) {
            $created = app(AgentConversationService::class)->create($context);
            $this->conversationRef = (string) $created->public_ref;

            return $created;
        }

        $conversation = app(AgentConversationService::class)->findForContext($this->conversationRef, $context);
        if ($conversation === null) {
            $created = app(AgentConversationService::class)->create($context);
            $this->conversationRef = (string) $created->public_ref;

            return $created;
        }

        return $conversation;
    }
}
