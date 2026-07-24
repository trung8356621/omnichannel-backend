<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Automation\Actions\Article;

use App\Addons\SeoContentAi\Automation\Contracts\BusinessAction;
use App\Addons\SeoContentAi\Automation\Data\ActionContext;
use App\Addons\SeoContentAi\Automation\Data\ActionDefinition;
use App\Addons\SeoContentAi\Automation\Data\ActionResult;
use App\Addons\SeoContentAi\Automation\Enums\ActionRiskLevel;
use App\Addons\SeoContentAi\Automation\Enums\ActionSelectability;
use App\Addons\SeoContentAi\Automation\Enums\ActionSideEffect;
use App\Addons\SeoContentAi\Automation\Support\ActionSupport;
use App\Addons\SeoContentAi\Automation\Support\ArticleContentConflictGuard;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Services\ArticleEditorPersistService;
use App\Addons\SeoContentAi\Services\ArticleLastSavedTimestampService;
use App\Addons\SeoContentAi\Support\ArticleEditorSaveContext;
use App\Addons\SeoContentAi\Support\SeoAccessControl;
use Illuminate\Support\Facades\DB;

/**
 * Local content update via ArticleEditorPersistService only (not Orchestrator).
 */
final class UpdateArticleContentAction implements BusinessAction
{
    public function __construct(
        private readonly ArticleEditorPersistService $persistService,
        private readonly ArticleContentConflictGuard $conflictGuard,
        private readonly ArticleLastSavedTimestampService $lastSavedTimestamps,
    ) {}

    public static function definition(): ActionDefinition
    {
        return new ActionDefinition(
            key: 'article.content.update',
            name: 'Update article content (local)',
            description: 'Update local article body/title. Must not call WordPress.',
            module: 'article',
            sideEffect: ActionSideEffect::InternalWrite,
            riskLevel: ActionRiskLevel::Medium,
            selectability: ActionSelectability::Selectable,
            inputSchema: [
                'article_id' => ['type' => 'integer', 'required' => true],
                'content' => ['type' => 'string', 'required' => true],
                'title' => ['type' => 'string', 'required' => false],
                'slug' => ['type' => 'string', 'required' => false],
                'status' => ['type' => 'string', 'required' => false],
                'post_type' => ['type' => 'string', 'required' => false],
                'visibility' => ['type' => 'string', 'required' => false],
                'publish_day' => ['type' => 'string', 'required' => false],
                'publish_month' => ['type' => 'string', 'required' => false],
                'publish_year' => ['type' => 'string', 'required' => false],
                'publish_hour' => ['type' => 'string', 'required' => false],
                'publish_minute' => ['type' => 'string', 'required' => false],
                'seo_meta_description' => ['type' => 'string', 'required' => false],
                'focus_keyword' => ['type' => 'string', 'required' => false],
                'expected_updated_at' => ['type' => 'string', 'required' => false],
                'expected_content_hash' => ['type' => 'string', 'required' => false],
                'force_overwrite' => ['type' => 'boolean', 'required' => false],
            ],
            outputSchema: [
                'article_id' => ['type' => 'integer'],
                'status' => ['type' => 'string'],
                'content_hash' => ['type' => 'string'],
            ],
            idempotent: true,
            lockScope: 'article',
            supportsDryRun: true,
            emittedEvents: ['article.content_updated'],
        );
    }

    public function execute(ActionContext $context, array $input): ActionResult
    {
        if ($denied = ActionSupport::assertMutable($context)) {
            return $denied;
        }

        $articleId = (int) ($input['article_id'] ?? 0);
        $article = ActionSupport::findArticle($articleId);
        if ($article === null) {
            return ActionResult::failure('article_not_found', "Article [{$articleId}] not found.");
        }

        $forceOverwrite = SeoAccessControl::canForceArticleContentOverwrite()
            || filter_var($input['force_overwrite'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if (! $forceOverwrite) {
            if ($conflict = $this->conflictGuard->assertCompatible($article, $input)) {
                return $conflict;
            }
        }

        $content = (string) ($input['content'] ?? '');
        $title = trim((string) ($input['title'] ?? $article->title ?? ''));
        $slugInput = array_key_exists('slug', $input)
            ? trim((string) $input['slug'])
            : null;

        if ($context->dryRun) {
            return ActionResult::success(
                output: [
                    'article_id' => $articleId,
                    'dry_run' => true,
                    'content_hash' => $this->conflictGuard->contentHash((string) ($article->body ?? '')),
                    'updated_at' => $article->updated_at?->toIso8601String(),
                    'force_overwrite' => $forceOverwrite,
                ],
                status: \App\Addons\SeoContentAi\Automation\Enums\ActionRunStatus::DryRun,
            );
        }

        $saveContext = ArticleEditorSaveContext::fromBundle($article, $this->buildEditorBundle($article, $title, $slugInput, $input));

        try {
            $result = ActionSupport::withArticleLock($articleId, function () use ($article, $saveContext, $content, $input, $forceOverwrite) {
                return DB::connection('omi_seo_ai')->transaction(function () use ($article, $saveContext, $content, $input, $forceOverwrite) {
                    $fresh = $article->fresh();
                    if ($fresh === null) {
                        throw new \RuntimeException('Article disappeared during lock.');
                    }
                    if (! $forceOverwrite) {
                        if ($conflict = $this->conflictGuard->assertCompatible($fresh, $input)) {
                            throw new ArticleContentConflictException($conflict);
                        }
                    }

                    return $this->persistService->persistLocal($fresh, $saveContext, $content, deferSeoAnalysis: true);
                });
            });
        } catch (ArticleContentConflictException $exception) {
            return $exception->result;
        } catch (\Throwable $exception) {
            return ActionResult::failure('persist_failed', $exception->getMessage());
        }

        if (! ($result['success'] ?? false)) {
            return ActionResult::failure('persist_rejected', (string) ($result['message'] ?? 'Persist failed.'));
        }

        $fresh = $article->fresh();
        if (
            $fresh instanceof SeoArticle
            && $this->lastSavedTimestamps->shouldTouchManualFromOrigin($context->origin)
        ) {
            $this->lastSavedTimestamps->touchManualSaved($fresh);
            $fresh = $fresh->fresh() ?? $fresh;
        }

        return ActionResult::success(
            output: [
                'article_id' => $articleId,
                'status' => (string) ($fresh?->status ?? $article->status ?? 'draft'),
                'message' => (string) ($result['message'] ?? ''),
                'content_hash' => $this->conflictGuard->contentHash((string) ($fresh?->body ?? $content)),
                'updated_at' => $fresh?->updated_at?->toIso8601String(),
            ],
            events: [
                ActionSupport::articleEvent('article.content_updated', $context, $articleId, [
                    'changed_fields' => ['content', 'title'],
                ]),
            ],
            changed: ['content', 'title'],
        );
    }

    /**
     * Build ArticleEditorSaveContext bundle từ input Action — giữ shape giống editor bundle
     * (article_meta/publish_box) để không mất publish status/visibility/SEO revision snapshot
     * khi cutover từ controller trực tiếp sang Action.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function buildEditorBundle(SeoArticle $article, string $title, ?string $slugInput, array $input): array
    {
        $articleMeta = [
            'title' => $title !== '' ? $title : (string) $article->title,
            'slug' => $slugInput !== null && $slugInput !== ''
                ? $slugInput
                : (string) ($article->slug ?? ''),
        ];
        if (array_key_exists('seo_meta_description', $input)) {
            $articleMeta['seo_meta_description'] = (string) $input['seo_meta_description'];
        }
        if (array_key_exists('focus_keyword', $input)) {
            $articleMeta['focus_keyword'] = (string) $input['focus_keyword'];
        }

        $publishBox = [
            'status' => (string) ($input['status'] ?? $article->status ?? 'draft'),
            'post_type' => (string) ($input['post_type'] ?? $article->type ?? 'article'),
        ];
        foreach (['visibility', 'publish_day', 'publish_month', 'publish_year', 'publish_hour', 'publish_minute'] as $field) {
            if (array_key_exists($field, $input)) {
                $publishBox[$field] = (string) $input[$field];
            }
        }

        return [
            'article_meta' => $articleMeta,
            'publish_box' => $publishBox,
        ];
    }
}
