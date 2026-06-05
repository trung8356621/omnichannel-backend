<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Exceptions\PromptRunException;
use App\Addons\SeoContentAi\Support\KeywordFocusAttach;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Models\SeoPrompt;
use App\Addons\SeoContentAi\Models\SeoTask;
use App\Addons\SeoContentAi\Support\TaskTestContext;
use App\Addons\SeoContentAi\Support\WorkflowExecutionState;
use App\Models\Site;

final class TaskWorkflowTestRunner
{
    public function __construct(
        private readonly PromptRunnerService $promptRunner,
        private readonly WorkflowParserService $workflowParser,
        private readonly WorkflowKeywordResearchService $keywordResearch,
        private readonly SeoFaqPersistenceService $faqPersistence,
        private readonly WorkflowTagExtractorService $tagExtractor,
        private readonly WordPressCommentReviewService $commentReviewPublisher,
        private readonly PromptTestPublishService $promptPublisher,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function run(SeoTask $task, TaskTestContext $context): array
    {
        $flow = is_array($task->flow_data) ? $task->flow_data : [];
        $edges = is_array($flow['edges'] ?? null) ? $flow['edges'] : [];
        $ordered = $this->orderedNodesForTask($task);
        $state = $this->initialState($context);
        $steps = [];

        foreach ($ordered as $node) {
            try {
                $steps[] = $this->executeNode($node, $context, $state, $edges);
            } catch (\Throwable $exception) {
                // Một node lỗi không được làm mất toàn bộ các bước đã chạy → ghi nhận bước «failed».
                $steps[] = [
                    'node_id' => (string) ($node['id'] ?? ''),
                    'type' => (string) ($node['type'] ?? ''),
                    'title' => (string) ($node['title'] ?? 'Bước'),
                    'status' => 'failed',
                    'message' => $exception->getMessage(),
                ];
            }
        }

        return $steps;
    }

    /**
     * @param  list<array<string, mixed>>  $priorSteps
     * @return array<string, mixed>
     */
    public function runSingleStep(
        SeoTask $task,
        TaskTestContext $context,
        string $nodeId,
        array $priorSteps = [],
    ): array {
        $ordered = $this->orderedNodesForTask($task);
        $flow = is_array($task->flow_data) ? $task->flow_data : [];
        $edges = is_array($flow['edges'] ?? null) ? $flow['edges'] : [];
        $state = $this->buildStateFromSteps($priorSteps, $context);

        foreach ($ordered as $node) {
            if ((string) ($node['id'] ?? '') === $nodeId) {
                return $this->executeNode($node, $context, $state, $edges);
            }
        }

        throw new \InvalidArgumentException('Không tìm thấy bước quy trình: ' . $nodeId);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function orderedNodesForTask(SeoTask $task): array
    {
        $flow = is_array($task->flow_data) ? $task->flow_data : [];
        $nodes = is_array($flow['nodes'] ?? null) ? $flow['nodes'] : [];
        $edges = is_array($flow['edges'] ?? null) ? $flow['edges'] : [];

        if ($nodes === []) {
            throw new \InvalidArgumentException('Quy trình chưa có sơ đồ (flow). Mở Builder để thiết kế.');
        }

        return $this->orderedNodes($nodes, $edges);
    }

    private function initialState(TaskTestContext $context): WorkflowExecutionState
    {
        $state = new WorkflowExecutionState();
        $state->article = $context->article;

        return $state;
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     */
    private function buildStateFromSteps(array $steps, TaskTestContext $context): WorkflowExecutionState
    {
        $state = $this->initialState($context);

        foreach ($steps as $step) {
            $this->applyCompletedStepToState($step, $state);
        }

        return $state;
    }

    /**
     * @param  array<string, mixed>  $step
     */
    private function applyCompletedStepToState(array $step, WorkflowExecutionState $state): void
    {
        $type = (string) ($step['type'] ?? '');

        if ($type === 'prompt' && filled($step['output'] ?? null)) {
            $nodeId = (string) ($step['node_id'] ?? '');
            $output = (string) $step['output'];
            $state->lastPromptOutput = $output;

            if ($nodeId !== '') {
                $outputs = is_array($step['outputs'] ?? null) ? $step['outputs'] : ['out_main' => $output];
                $state->nodeOutputs[$nodeId] = array_map(
                    static fn ($value): string => is_string($value) ? $value : (string) $value,
                    $outputs,
                );
            }
        }

        if ($type === 'filter' && ($step['status'] ?? '') === 'completed') {
            $nodeId = (string) ($step['node_id'] ?? '');
            if ($nodeId !== '' && filled($step['output'] ?? null)) {
                $state->nodeOutputs[$nodeId] = ['out_main' => (string) $step['output']];
            }

            $parsed = $step['parsed'] ?? null;
            if (! is_array($parsed)) {
                return;
            }

            $filterType = (string) ($step['filter_type'] ?? '');
            if ($filterType === 'parse_outline') {
                $state->setParsedOutline($parsed);
            } elseif ($filterType === 'parse_keywords') {
                /** @var array<string, list<string>> $parsed */
                $state->setParsedKeywords($parsed);
            } elseif ($filterType === 'parse_faq') {
                $state->setParsedFaqs($parsed);
            }

            if (is_array($step['seo_score'] ?? null)) {
                $state->setSeoScoreData($step['seo_score']);
            }
        }

        if ($type === 'action' && is_numeric($step['article_id'] ?? null)) {
            $article = SeoArticle::query()->find((int) $step['article_id']);
            if ($article instanceof SeoArticle) {
                $state->article = $article;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private function executeNode(
        array $node,
        TaskTestContext $context,
        WorkflowExecutionState $state,
        array $edges = [],
    ): array {
        $type = (string) ($node['type'] ?? '');
        $nodeId = (string) ($node['id'] ?? '');
        $title = (string) ($node['title'] ?? $type);
        $variables = $context->variables;

        if ($type === 'article') {
            return [
                'node_id' => $nodeId,
                'type' => $type,
                'title' => $title,
                'status' => 'ok',
                'message' => $context->summary,
            ];
        }

        if ($type === 'filter') {
            return $this->executeFilterNode($node, $state, $edges);
        }

        if ($type === 'action') {
            return $this->executeActionNode($node, $context, $state, $edges);
        }

        if ($type === 'prompt') {
            $promptId = $node['data']['promptId'] ?? null;
            $prompt = $this->resolvePrompt($promptId);

            if ($prompt === null) {
                return [
                    'node_id' => $nodeId,
                    'type' => $type,
                    'title' => $title,
                    'status' => 'failed',
                    'message' => 'Không tìm thấy prompt #' . (string) $promptId,
                ];
            }

            try {
                $input = $this->resolveInputForNode($nodeId, $edges, $state);
                if ($input !== '') {
                    $variables['input'] = $input;
                }

                $model = trim((string) ($node['data']['aiModel'] ?? ''));
                $result = $this->promptRunner->run(
                    $prompt,
                    $variables,
                    $model !== '' ? $model : null,
                    isTaskMode: true,
                );
                $output = trim((string) ($result->output_text ?? ''));

                if ($output !== '') {
                    $state->lastPromptOutput = $output;
                    $state->nodeOutputs[$nodeId] = $this->buildPromptNodeOutputs($prompt, $output, $state);
                    $this->refreshWorkflowSeoScore($state, $output);
                }

                if ($this->shouldMergeOutlineToSave($node) && trim($output) !== '') {
                    // «Viết bài theo dàn ý»: output đã là bài hoàn chỉnh (H1 + body + meta + FAQ)
                    //   → đẩy thẳng markdown bài viết cho action lưu vào body.
                    // input (edge) là dàn ý gốc → giữ lại để lưu vào tab Dàn ý (seo_article_outline),
                    //   KHÔNG để nội dung bài đè lên dàn ý.
                    $state->meta['direct_publish_article_markdown'] = trim($output);

                    $outlineSource = $this->cleanWorkflowOutlineMarkdown($input);
                    if ($outlineSource !== '') {
                        $state->meta['direct_publish_outline_markdown'] = $outlineSource;
                    }
                }

                return [
                    'node_id' => $nodeId,
                    'type' => $type,
                    'title' => $title,
                    'status' => $result->status === 'completed' ? 'completed' : 'failed',
                    'prompt_id' => $prompt->id,
                    'prompt_name' => (string) $prompt->name,
                    'ai_model' => $model !== '' ? $model : null,
                    'input_used' => $input !== '' ? mb_substr($input, 0, 120) . (mb_strlen($input) > 120 ? '…' : '') : null,
                    'output' => $output,
                    'outputs' => $state->nodeOutputs[$nodeId] ?? [],
                    'result_id' => $result->id,
                    'message' => $result->status === 'completed'
                        ? 'Chạy prompt thành công.'
                        : (string) ($result->error_message ?? 'Prompt thất bại.'),
                ];
            } catch (PromptRunException $exception) {
                return [
                    'node_id' => $nodeId,
                    'type' => $type,
                    'title' => $title,
                    'status' => 'failed',
                    'prompt_id' => $prompt->id,
                    'prompt_name' => (string) $prompt->name,
                    'message' => $exception->getMessage(),
                ];
            }
        }

        return [
            'node_id' => $nodeId,
            'type' => $type,
            'title' => $title,
            'status' => 'skipped',
            'message' => 'Loại node không hỗ trợ: ' . $type,
        ];
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private function executeFilterNode(array $node, WorkflowExecutionState $state, array $edges): array
    {
        $nodeId = (string) ($node['id'] ?? '');
        $title = (string) ($node['title'] ?? 'filter');
        $filterType = (string) ($node['data']['filterType'] ?? 'custom');
        $inputData = trim($this->resolveInputForNode($nodeId, $edges, $state));
        $filterTag = trim((string) ($node['data']['filterTag'] ?? ''));
        $tagKey = trim((string) ($node['data']['tagKey'] ?? ($filterTag === '__custom__'
            ? ($node['data']['customTag'] ?? '')
            : $filterTag)));

        if ($filterType === 'extract_segment') {
            if ($inputData === '') {
                return [
                    'node_id' => $nodeId,
                    'type' => 'filter',
                    'title' => $title,
                    'filter_type' => $filterType,
                    'status' => 'failed',
                    'message' => 'Không có dữ liệu đầu vào để bóc tách.',
                ];
            }

            if ($tagKey === '') {
                return [
                    'node_id' => $nodeId,
                    'type' => 'filter',
                    'title' => $title,
                    'filter_type' => $filterType,
                    'status' => 'failed',
                    'message' => 'Chưa cấu hình tên bộ lọc cần bóc tách.',
                ];
            }

            $extract = $this->tagExtractor->extractSegment($inputData, $tagKey);
            $normalizedTag = strtoupper(preg_replace('/[^A-Z0-9]+/i', '_', $tagKey) ?? '');
            $normalizedTag = trim($normalizedTag, '_');
            if ($normalizedTag === '') {
                $normalizedTag = 'UNKNOWN_TAG';
            }

            if (! $extract['matched']) {
                $state->meta['workflow_warnings'][] = sprintf('Không tìm thấy segment cho tag "%s".', $tagKey);
                $state->nodeOutputs[$nodeId] = ['out_main' => ''];
                $state->meta['extracted_segments'][$normalizedTag] = '';

                return [
                    'node_id' => $nodeId,
                    'type' => 'filter',
                    'title' => $title,
                    'filter_type' => $filterType,
                    'status' => 'completed',
                    'message' => sprintf('Không tìm thấy segment [%s], trả về rỗng.', $normalizedTag),
                    'parsed' => ['tag' => $normalizedTag, 'content' => ''],
                    'output' => '',
                    'outputs' => ['out_main' => ''],
                ];
            }

            $segmentContent = trim((string) ($extract['content'] ?? ''));
            $state->meta['extracted_segments'][$normalizedTag] = $segmentContent;
            $state->nodeOutputs[$nodeId] = ['out_main' => $segmentContent];

            return [
                'node_id' => $nodeId,
                'type' => 'filter',
                'title' => $title,
                'filter_type' => $filterType,
                'status' => 'completed',
                'message' => sprintf('Đã bóc tách segment [%s].', $normalizedTag),
                'parsed' => ['tag' => $normalizedTag, 'content' => $segmentContent],
                'output' => $segmentContent,
                'outputs' => ['out_main' => $segmentContent],
            ];
        }

        if ($filterType === 'custom') {
            return [
                'node_id' => $nodeId,
                'type' => 'filter',
                'title' => $title,
                'filter_type' => $filterType,
                'status' => 'skipped',
                'message' => 'Quy tắc lọc tùy chỉnh chưa được hỗ trợ trong chạy thử.',
            ];
        }

        if ($inputData === '') {
            return [
                'node_id' => $nodeId,
                'type' => 'filter',
                'title' => $title,
                'filter_type' => $filterType,
                'status' => 'failed',
                'message' => 'Không có kết quả Markdown từ bước Prompt trước đó.',
            ];
        }

        if ($filterType === 'parse_outline') {
            $parsedResult = $this->workflowParser->parseOutline($inputData);
            $state->setParsedOutline($parsedResult);
            $this->refreshWorkflowSeoScore($state, $inputData);
            $jsonOutput = json_encode($parsedResult, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            $state->nodeOutputs[$nodeId] = ['out_main' => $jsonOutput];

            return $this->filterStepResponse(
                $nodeId,
                $title,
                $filterType,
                'Đã bóc tách dàn ý (' . count($parsedResult) . ' mục H2/H3).',
                $parsedResult,
                $jsonOutput,
                $state,
            );
        }

        if ($filterType === 'parse_keywords') {
            $parsedResult = $this->workflowParser->parseKeywords($inputData);
            $state->setParsedKeywords($parsedResult);
            $this->refreshWorkflowSeoScore($state, $inputData);
            $jsonOutput = json_encode($parsedResult, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            $state->nodeOutputs[$nodeId] = ['out_main' => $jsonOutput];

            $keywordCount = array_sum(array_map('count', $parsedResult));

            return $this->filterStepResponse(
                $nodeId,
                $title,
                $filterType,
                'Đã bóc tách từ khóa (' . count($parsedResult) . ' nhóm, ' . $keywordCount . ' từ).',
                $parsedResult,
                $jsonOutput,
                $state,
            );
        }

        if ($filterType === 'parse_faq') {
            $parsedResult = $this->workflowParser->parseFaqsFromContent($inputData);
            $cleanedMarkdown = $this->workflowParser->removeFaqAndAppendShortcodeFromContent($inputData);
            $state->setParsedFaqs($parsedResult);
            $state->lastPromptOutput = $cleanedMarkdown;
            $this->refreshWorkflowSeoScore($state, $inputData);
            $jsonOutput = json_encode($parsedResult, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            $state->nodeOutputs[$nodeId] = ['out_main' => $cleanedMarkdown];

            return $this->filterStepResponse(
                $nodeId,
                $title,
                $filterType,
                'Đã bóc tách FAQ (' . count($parsedResult) . ' câu) và chèn [omi_faq].',
                $parsedResult,
                $jsonOutput,
                $state,
            );
        }

        if ($filterType === 'score_seo') {
            $this->refreshWorkflowSeoScore($state, $inputData);
            $scoreData = is_array($state->meta['seo_score_data'] ?? null) ? $state->meta['seo_score_data'] : [];
            $total = (int) ($scoreData['total_score'] ?? 0);
            $jsonOutput = json_encode($scoreData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            $state->nodeOutputs[$nodeId] = ['out_main' => $jsonOutput];

            return $this->filterStepResponse(
                $nodeId,
                $title,
                $filterType,
                'Đã chấm điểm SEO tự động (+' . $total . ' điểm).',
                $scoreData,
                $jsonOutput,
                $state,
            );
        }

        return [
            'node_id' => $nodeId,
            'type' => 'filter',
            'title' => $title,
            'filter_type' => $filterType,
            'status' => 'skipped',
            'message' => 'Loại lọc không hỗ trợ: ' . $filterType,
        ];
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    /**
     * @param  list<array<string, mixed>>  $edges
     */
    private function executeActionNode(
        array $node,
        TaskTestContext $context,
        WorkflowExecutionState $state,
        array $edges,
    ): array {
        $nodeId = (string) ($node['id'] ?? '');
        $title = (string) ($node['title'] ?? 'action');
        $actionType = (string) ($node['data']['actionType'] ?? 'save_article');

        if ($actionType === 'post_comment_review') {
            return $this->executePostCommentReviewAction($node, $context, $state, $edges);
        }

        if ($actionType === 'save_vocabulary_research') {
            return $this->executeSaveVocabularyResearchAction($node, $context, $state);
        }

        $article = $state->article ?? $context->article;

        if ($this->isArticlePersistAction($actionType) && $article === null) {
            $article = $this->createArticleFromContext($context);
            if ($article === null) {
                return [
                    'node_id' => $nodeId,
                    'type' => 'action',
                    'title' => $title,
                    'action_type' => $actionType,
                    'status' => 'failed',
                    'message' => 'Không thể tạo bài viết: chưa có website/domain để gán bài.',
                ];
            }
            $state->article = $article;
        }

        if ($article === null) {
            return [
                'node_id' => $nodeId,
                'type' => 'action',
                'title' => $title,
                'action_type' => $actionType,
                'status' => 'failed',
                'message' => 'Không có bài viết đích để lưu meta.',
            ];
        }

        $messages = [];
        $articleMarkdown = trim((string) ($state->meta['direct_publish_article_markdown'] ?? ''));

        if ($articleMarkdown === '') {
            $fallbackMarkdown = trim((string) ($state->lastPromptOutput ?? ''));
            if ($fallbackMarkdown !== '' && $this->shouldPublishMarkdownAsArticle($fallbackMarkdown)) {
                $articleMarkdown = $fallbackMarkdown;
            }
        }

        if ($articleMarkdown !== '') {
            try {
                $publish = $this->promptPublisher->publishArticle(
                    $article,
                    $articleMarkdown,
                    $context->variables,
                );
            } catch (\Throwable $exception) {
                // Không để lỗi đăng bài làm hỏng cả quy trình test → trả về bước «failed» có thông báo.
                return [
                    'node_id' => $nodeId,
                    'type' => 'action',
                    'title' => $title,
                    'action_type' => $actionType,
                    'status' => 'failed',
                    'article_id' => $article->id,
                    'message' => 'Lỗi khi lưu nội dung bài viết: ' . $exception->getMessage(),
                ];
            }

            if (! ($publish['success'] ?? false)) {
                return [
                    'node_id' => $nodeId,
                    'type' => 'action',
                    'title' => $title,
                    'action_type' => $actionType,
                    'status' => 'failed',
                    'article_id' => $article->id,
                    'message' => (string) ($publish['message'] ?? 'Không thể lưu nội dung bài viết từ markdown.'),
                ];
            }

            $article = $article->fresh() ?? $article;
            $state->article = $article;
            $state->meta['article_markdown_published'] = true;
            $messages[] = (string) ($publish['message'] ?? 'Đã lưu nội dung bài viết (tiêu đề + body + meta).');

            $outlineMarkdown = trim((string) ($state->meta['direct_publish_outline_markdown'] ?? ''));
            if ($outlineMarkdown !== '') {
                $article->articleMetas()->updateOrCreate(
                    ['meta_key' => 'seo_article_outline'],
                    ['meta_value' => $outlineMarkdown],
                );
            }
        }

        $savedKeys = $this->persistWorkflowMeta($article, $state);
        if ($savedKeys !== []) {
            $messages[] = 'Đã lưu meta: ' . implode(', ', $savedKeys);
        }

        if ($this->keywordResearch->shouldSyncKeywords($actionType, $state)) {
            try {
                $sync = $this->syncKeywordResearchForArticle($article, $context, $state);
                $messages[] = sprintf(
                    'Đã lưu nghiên cứu từ vựng — cụm «%s» + %d từ khóa con.',
                    $sync['parent_phrase'],
                    $sync['children_count'],
                );
            } catch (\InvalidArgumentException $exception) {
                if ($actionType === 'save_vocabulary_research') {
                    return [
                        'node_id' => $nodeId,
                        'type' => 'action',
                        'title' => $title,
                        'action_type' => $actionType,
                        'status' => 'failed',
                        'article_id' => $article->id,
                        'message' => $exception->getMessage(),
                    ];
                }
            }
        }

        return [
            'node_id' => $nodeId,
            'type' => 'action',
            'title' => $title,
            'action_type' => $actionType,
            'status' => 'completed',
            'article_id' => $article->id,
            'message' => $messages === []
                ? 'Hành động hoàn tất (không có meta/từ khóa để lưu).'
                : implode(' ', $messages),
            'output' => json_encode($state->meta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        ];
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  list<array<string, mixed>>  $edges
     * @return array<string, mixed>
     */
    private function executePostCommentReviewAction(
        array $node,
        TaskTestContext $context,
        WorkflowExecutionState $state,
        array $edges,
    ): array {
        $nodeId = (string) ($node['id'] ?? '');
        $title = (string) ($node['title'] ?? 'action');
        $actionType = 'post_comment_review';

        $article = $state->article ?? $context->article;
        if (! $article instanceof SeoArticle) {
            return [
                'node_id' => $nodeId,
                'type' => 'action',
                'title' => $title,
                'action_type' => $actionType,
                'status' => 'failed',
                'message' => 'Không có bài viết đích để đăng review/bình luận.',
            ];
        }

        $input = $this->resolveInputForNode($nodeId, $edges, $state);
        if ($input === '') {
            $input = trim((string) ($state->lastPromptOutput ?? ''));
        }

        if ($input === '') {
            return [
                'node_id' => $nodeId,
                'type' => 'action',
                'title' => $title,
                'action_type' => $actionType,
                'status' => 'failed',
                'article_id' => $article->id,
                'message' => 'Không có kết quả AI để đăng review/bình luận.',
            ];
        }

        $result = $this->commentReviewPublisher->publishFromAiOutput($article->fresh() ?? $article, $input);

        return [
            'node_id' => $nodeId,
            'type' => 'action',
            'title' => $title,
            'action_type' => $actionType,
            'status' => ($result['success'] ?? false) ? 'completed' : 'failed',
            'article_id' => $article->id,
            'message' => (string) ($result['message'] ?? ''),
            'created_count' => (int) ($result['created_count'] ?? 0),
        ];
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private function executeSaveVocabularyResearchAction(
        array $node,
        TaskTestContext $context,
        WorkflowExecutionState $state,
    ): array {
        $nodeId = (string) ($node['id'] ?? '');
        $title = (string) ($node['title'] ?? 'action');
        $actionType = 'save_vocabulary_research';

        $article = $state->article ?? $context->article;

        if ($article === null) {
            return [
                'node_id' => $nodeId,
                'type' => 'action',
                'title' => $title,
                'action_type' => $actionType,
                'status' => 'failed',
                'message' => 'Không tìm thấy bài viết đích để lưu nghiên cứu từ vựng.',
            ];
        }

        $savedKeys = $this->persistWorkflowMeta($article, $state);

        try {
            $sync = $this->syncKeywordResearchForArticle($article, $context, $state);
        } catch (\InvalidArgumentException $exception) {
            return [
                'node_id' => $nodeId,
                'type' => 'action',
                'title' => $title,
                'action_type' => $actionType,
                'status' => 'failed',
                'article_id' => $article->id,
                'message' => $exception->getMessage(),
            ];
        }

        $metaNote = $savedKeys !== [] ? ' Meta: ' . implode(', ', $savedKeys) . '.' : '';

        return [
            'node_id' => $nodeId,
            'type' => 'action',
            'title' => $title,
            'action_type' => $actionType,
            'status' => 'completed',
            'article_id' => $article->id,
            'message' => sprintf(
                'Đã lưu nghiên cứu từ vựng — cụm «%s» (#%d) + %d từ khóa con (Topic Cluster).%s',
                $sync['parent_phrase'],
                $sync['parent_id'],
                $sync['children_count'],
                $metaNote,
            ),
            'output' => json_encode([
                'topic_cluster' => $sync,
                'meta' => $state->meta,
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        ];
    }

    /**
     * @return array{parent_id: int, parent_phrase: string, children_count: int}
     */
    private function syncKeywordResearchForArticle(
        SeoArticle $article,
        TaskTestContext $context,
        WorkflowExecutionState $state,
    ): array {
        $groups = $this->keywordResearch->keywordGroupsFromState($state);
        $focusPhrase = $this->keywordResearch->resolveFocusPhrase($article, $context);

        return $this->keywordResearch->syncTopicCluster($article, $groups, $focusPhrase);
    }

    /**
     * @return list<string>
     */
    public function persistWorkflowMeta(SeoArticle $article, WorkflowExecutionState $state): array
    {
        $savedKeys = [];

        if (isset($state->meta['seo_article_outlines']) && is_array($state->meta['seo_article_outlines'])) {
            $article->articleMetas()->updateOrCreate(
                ['meta_key' => 'seo_article_outlines'],
                ['meta_value' => json_encode($state->meta['seo_article_outlines'], JSON_UNESCAPED_UNICODE)],
            );
            $savedKeys[] = 'seo_article_outlines';
        }

        if (isset($state->meta['seo_article_keywords']) && is_array($state->meta['seo_article_keywords'])) {
            $article->articleMetas()->updateOrCreate(
                ['meta_key' => 'seo_article_keywords'],
                ['meta_value' => json_encode($state->meta['seo_article_keywords'], JSON_UNESCAPED_UNICODE)],
            );
            $savedKeys[] = 'seo_article_keywords';
        }

        if (
            filled($state->lastPromptOutput)
            && trim((string) ($state->meta['direct_publish_article_markdown'] ?? '')) === ''
            && ! ($state->meta['article_markdown_published'] ?? false)
            && ! $this->shouldPublishMarkdownAsArticle(trim((string) $state->lastPromptOutput))
        ) {
            $article->articleMetas()->updateOrCreate(
                ['meta_key' => 'seo_article_outline'],
                ['meta_value' => $state->lastPromptOutput],
            );
            $savedKeys[] = 'seo_article_outline';
        }

        if (
            isset($state->meta['seo_article_faqs'])
            && is_array($state->meta['seo_article_faqs'])
            && $state->meta['seo_article_faqs'] !== []
        ) {
            $faqCount = $this->faqPersistence->persistForArticle(
                $article,
                $state->meta['seo_article_faqs'],
            );

            if ($faqCount > 0) {
                $savedKeys[] = 'seo_faqs';
                $this->applyFaqStrippedArticleContent($article, $state);
            }
        }

        $scoreLabel = $this->applyWorkflowSeoScoreToArticle($article, $state);
        if ($scoreLabel !== null) {
            $savedKeys[] = $scoreLabel;
        }

        return $savedKeys;
    }

    private function applyFaqStrippedArticleContent(SeoArticle $article, WorkflowExecutionState $state): void
    {
        $markdown = trim((string) ($state->lastPromptOutput ?? ''));

        if ($markdown === '') {
            return;
        }

        app(ArticleContentFaqService::class)->applyStrippedContentToArticle($article, $markdown);
    }

    private function refreshWorkflowSeoScore(WorkflowExecutionState $state, string $markdown): void
    {
        $faqs = $state->meta['seo_article_faqs'] ?? [];
        if (! is_array($faqs)) {
            $faqs = [];
        }

        $state->setSeoScoreData($this->workflowParser->calculateSeoScoreFromContent($markdown, $faqs));
    }

    /**
     * @return array<string, mixed>
     */
    private function filterStepResponse(
        string $nodeId,
        string $title,
        string $filterType,
        string $message,
        mixed $parsed,
        string $jsonOutput,
        WorkflowExecutionState $state,
    ): array {
        $response = [
            'node_id' => $nodeId,
            'type' => 'filter',
            'title' => $title,
            'filter_type' => $filterType,
            'status' => 'completed',
            'message' => $message,
            'parsed' => $parsed,
            'output' => $jsonOutput,
        ];

        if (isset($state->meta['seo_score_data']) && is_array($state->meta['seo_score_data'])) {
            $response['seo_score'] = $state->meta['seo_score_data'];
        }

        return $response;
    }

    private function applyWorkflowSeoScoreToArticle(SeoArticle $article, WorkflowExecutionState $state): ?string
    {
        $scoreData = $state->meta['seo_score_data'] ?? null;
        if (! is_array($scoreData) || ! isset($scoreData['total_score'])) {
            return null;
        }

        $bonus = (int) $scoreData['total_score'];
        if ($bonus <= 0) {
            $article->articleMetas()->updateOrCreate(
                ['meta_key' => 'seo_scoring_details'],
                ['meta_value' => json_encode($scoreData['checklist'] ?? [], JSON_UNESCAPED_UNICODE)],
            );

            return 'seo_scoring_details';
        }

        if (! $article->countsTowardSeoScore()) {
            $article->articleMetas()->updateOrCreate(
                ['meta_key' => 'seo_scoring_details'],
                ['meta_value' => json_encode($scoreData['checklist'] ?? [], JSON_UNESCAPED_UNICODE)],
            );

            return 'seo_scoring_details (skipped score)';
        }

        $current = $article->seo_score !== null ? (float) $article->seo_score : 0.0;

        $article->update([
            'seo_score' => $current + $bonus,
        ]);

        $article->articleMetas()->updateOrCreate(
            ['meta_key' => 'seo_scoring_details'],
            ['meta_value' => json_encode($scoreData['checklist'] ?? [], JSON_UNESCAPED_UNICODE)],
        );

        return 'seo_score (+' . $bonus . ')';
    }

    private function isArticlePersistAction(string $actionType): bool
    {
        return in_array($actionType, ['save_article', 'create_article', 'edit_article'], true);
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function shouldMergeOutlineToSave(array $node): bool
    {
        if (! (bool) ($node['data']['mergeOutlineToSave'] ?? false)) {
            return false;
        }

        $promptId = $node['data']['promptId'] ?? null;
        $prompt = $this->resolvePrompt($promptId);

        return $prompt !== null && $this->promptSupportsMergeOutlineSave($prompt);
    }

    private function promptSupportsMergeOutlineSave(SeoPrompt $prompt): bool
    {
        $name = mb_strtolower(trim((string) $prompt->name));

        if ($name === '') {
            return false;
        }

        if (str_contains($name, 'theo dàn')) {
            return true;
        }

        return str_contains($name, 'viết') && str_contains($name, 'dàn ý');
    }

    /**
     * Làm sạch dàn ý gốc (đầu vào prompt viết bài) để lưu vào tab «Dàn ý»:
     * bỏ các dòng đánh dấu tag [START_TASK_X] / [END_TASK_X] và gộp dòng trống thừa.
     */
    private function shouldPublishMarkdownAsArticle(string $markdown): bool
    {
        $markdown = trim($markdown);
        if ($markdown === '') {
            return false;
        }

        if (preg_match('/\*\*Meta Description:\*\*/iu', $markdown) === 1) {
            return true;
        }

        if (preg_match('/^#\s+\S+/mu', $markdown) === 1 && mb_strlen($markdown) >= 200) {
            return true;
        }

        if (preg_match('/^##\s+.+\R\R[^\n#\-*\d]/mu', $markdown) === 1) {
            return true;
        }

        return mb_strlen($markdown) >= 400;
    }

    private function cleanWorkflowOutlineMarkdown(string $input): string
    {
        $input = trim($input);
        if ($input === '') {
            return '';
        }

        $input = (string) preg_replace('/^\s*\[(?:START|END)_[A-Z0-9_]+\]\s*$/mu', '', $input);
        $input = (string) preg_replace("/\n{3,}/u", "\n\n", $input);

        return trim($input);
    }

    private function createArticleFromContext(TaskTestContext $context): ?SeoArticle
    {
        $siteId = $this->resolveSiteIdForNewArticle($context);
        if ($siteId === null) {
            return null;
        }

        $variables = $context->variables;
        $title = trim((string) ($variables['post_title'] ?? ''));
        if ($title === '') {
            $title = trim((string) ($variables['focus_keyword'] ?? 'Bài viết mới'));
        }

        $slugSource = trim((string) ($variables['focus_keyword'] ?? ''));
        if ($slugSource === '') {
            $slugSource = $title;
        }
        $slug = \Illuminate\Support\Str::slug($slugSource);

        $article = SeoArticle::query()->create([
            'site_id' => $siteId,
            'user_id' => auth()->id(),
            'type' => 'article',
            'title' => $title,
            'slug' => $slug !== '' ? $slug : null,
            'status' => 'draft',
            'body' => '',
            'language' => 'vi',
        ]);

        $focusKeyword = trim((string) ($variables['focus_keyword'] ?? ''));
        if ($focusKeyword !== '') {
            $article->articleMetas()->updateOrCreate(
                ['meta_key' => 'seo_focus_keyword'],
                ['meta_value' => $focusKeyword],
            );

            KeywordFocusAttach::attachMainKeyword(
                $article,
                $siteId,
                (int) auth()->id(),
                $focusKeyword,
            );
        }

        return $article;
    }

    private function resolveSiteIdForNewArticle(TaskTestContext $context): ?int
    {
        if ($context->article !== null) {
            return (int) $context->article->site_id;
        }

        $query = Site::query()->orderBy('id');

        if (auth()->user()?->role !== 'admin') {
            $query->where('user_id', auth()->id());
        }

        $siteId = $query->value('id');

        return is_numeric($siteId) ? (int) $siteId : null;
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     */
    public function applyParsedMetaFromSteps(SeoArticle $article, array $steps): void
    {
        $state = new WorkflowExecutionState();

        foreach ($steps as $step) {
            $this->applyCompletedStepToState($step, $state);
        }

        $this->persistWorkflowMeta($article, $state);
    }

    /**
     * @param  array<int, array<string, mixed>>  $nodes
     * @param  array<int, array<string, mixed>>  $edges
     * @return list<array<string, mixed>>
     */
    private function orderedNodes(array $nodes, array $edges): array
    {
        $byId = [];
        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }
            $id = (string) ($node['id'] ?? '');
            if ($id !== '') {
                $byId[$id] = $node;
            }
        }

        if ($byId === []) {
            return [];
        }

        $adjacency = [];
        $inDegree = array_fill_keys(array_keys($byId), 0);

        foreach ($edges as $edge) {
            if (! is_array($edge)) {
                continue;
            }
            $source = (string) ($edge['sourceNode'] ?? '');
            $target = (string) ($edge['targetNode'] ?? '');
            if ($source === '' || $target === '' || ! isset($byId[$source], $byId[$target])) {
                continue;
            }
            $adjacency[$source][] = $target;
            $inDegree[$target] = ($inDegree[$target] ?? 0) + 1;
        }

        $starts = [];
        foreach ($byId as $id => $node) {
            if (($node['type'] ?? '') === 'article') {
                $starts[] = $id;
            }
        }

        if ($starts === []) {
            foreach ($inDegree as $id => $degree) {
                if ($degree === 0) {
                    $starts[] = $id;
                }
            }
        }

        if ($starts === []) {
            $starts[] = array_key_first($byId);
        }

        $queue = $starts;
        $visited = [];
        $ordered = [];

        while ($queue !== []) {
            $id = array_shift($queue);
            if (isset($visited[$id])) {
                continue;
            }
            $visited[$id] = true;
            $ordered[] = $byId[$id];

            foreach ($adjacency[$id] ?? [] as $nextId) {
                if (! isset($visited[$nextId])) {
                    $queue[] = $nextId;
                }
            }
        }

        foreach ($byId as $id => $node) {
            if (! isset($visited[$id])) {
                $ordered[] = $node;
            }
        }

        return $ordered;
    }

    /**
     * @return array<string, string>
     */
    private function buildPromptNodeOutputs(SeoPrompt $prompt, string $output, WorkflowExecutionState $state): array
    {
        $outputs = ['out_main' => $output];

        $detectedTags = $this->tagExtractor->detectTagsFromPromptTemplate((string) ($prompt->markdown_content ?? ''));
        foreach ($detectedTags as $tag) {
            $tagId = trim((string) ($tag['id'] ?? ''));
            $tagKey = trim((string) ($tag['key'] ?? ''));
            if ($tagId === '' || $tagKey === '') {
                continue;
            }

            $extract = $this->tagExtractor->extractSegment($output, $tagKey);
            $segment = trim((string) ($extract['content'] ?? ''));
            $outputs['out_' . $tagId] = $segment;
            $state->meta['extracted_segments'][$tagKey] = $segment;
        }

        return $outputs;
    }

    /**
     * @param  array<int, array<string, mixed>>  $edges
     */
    private function resolveInputForNode(string $targetNodeId, array $edges, WorkflowExecutionState $state): string
    {
        foreach ($edges as $edge) {
            if (! is_array($edge)) {
                continue;
            }

            if ((string) ($edge['targetNode'] ?? '') !== $targetNodeId) {
                continue;
            }

            $sourceNodeId = (string) ($edge['sourceNode'] ?? '');
            $sourcePort = (string) ($edge['sourcePort'] ?? 'out_main');
            $value = $this->resolvePortOutput($state, $sourceNodeId, $sourcePort);

            if ($value !== '') {
                return $value;
            }
        }

        return trim((string) ($state->lastPromptOutput ?? ''));
    }

    private function resolvePortOutput(WorkflowExecutionState $state, string $sourceNodeId, string $sourcePort): string
    {
        if ($sourceNodeId === '') {
            return '';
        }

        $outputs = $state->nodeOutputs[$sourceNodeId] ?? [];

        if (isset($outputs[$sourcePort]) && trim($outputs[$sourcePort]) !== '') {
            return trim($outputs[$sourcePort]);
        }

        return trim((string) ($outputs['out_main'] ?? ''));
    }

    private function resolvePrompt(mixed $promptId): ?SeoPrompt
    {
        if ($promptId === null || $promptId === '') {
            return null;
        }

        if (is_numeric($promptId)) {
            return SeoPrompt::query()->where('is_active', true)->find((int) $promptId);
        }

        $idString = (string) $promptId;
        if (preg_match('/^p(\d+)$/', $idString, $matches)) {
            return SeoPrompt::query()->where('is_active', true)->find((int) $matches[1]);
        }

        return SeoPrompt::query()
            ->where('is_active', true)
            ->where('id', $idString)
            ->first();
    }
}
