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
use App\Addons\SeoContentAi\Services\ArticleWordPressSyncFlagService;
use App\Addons\SeoContentAi\Services\SeoArticleScoringQueueService;
use App\Addons\SeoContentAi\Support\KeywordFocusAttach;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Local SEO meta only — không dùng ArticleEditorSeoMetaService (có WP content service trong ctor/response).
 */
final class UpdateArticleSeoMetaAction implements BusinessAction
{
    public function __construct(
        private readonly ArticleWordPressSyncFlagService $syncFlags,
        private readonly SeoArticleScoringQueueService $scoringQueue,
    ) {}

    public static function definition(): ActionDefinition
    {
        return new ActionDefinition(
            key: 'article.seo_meta.update',
            name: 'Update article SEO meta (local)',
            description: 'Update local SEO meta fields. Never calls WordPress API.',
            module: 'article',
            sideEffect: ActionSideEffect::InternalWrite,
            riskLevel: ActionRiskLevel::Medium,
            selectability: ActionSelectability::Selectable,
            inputSchema: [
                'article_id' => ['type' => 'integer', 'required' => true],
                'focus_keyword' => ['type' => 'string', 'required' => false],
                'meta_description' => ['type' => 'string', 'required' => false],
                'slug' => ['type' => 'string', 'required' => false],
                // false: caller sẽ analyze/score sau (tránh double queue khi wire project publish).
                'dispatch_scoring' => ['type' => 'boolean', 'required' => false],
            ],
            outputSchema: [
                'article_id' => ['type' => 'integer'],
                'focus_keyword' => ['type' => 'string'],
                'meta_description' => ['type' => 'string'],
                'slug' => ['type' => 'string'],
            ],
            idempotent: true,
            lockScope: 'article',
            emittedEvents: ['article.seo_meta_updated'],
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

        $focusKeyword = trim((string) ($input['focus_keyword'] ?? ''));
        $metaDescription = trim((string) ($input['meta_description'] ?? ''));
        $normalizedSlug = Str::slug(trim((string) ($input['slug'] ?? '')));

        try {
            ActionSupport::withArticleLock($articleId, function () use ($article, $focusKeyword, $metaDescription, $normalizedSlug, $context): void {
                DB::connection('omi_seo_ai')->transaction(function () use ($article, $focusKeyword, $metaDescription, $normalizedSlug, $context): void {
                    foreach (['seo_meta_description', 'meta_description'] as $key) {
                        if ($metaDescription === '') {
                            $article->articleMetas()->where('meta_key', $key)->delete();
                        } else {
                            $article->articleMetas()->updateOrCreate(
                                ['meta_key' => $key],
                                ['meta_value' => $metaDescription],
                            );
                        }
                    }

                    $siteId = (int) ($article->site_id ?? 0);
                    $actorId = $context->actorId ?? (auth()->id() !== null ? (int) auth()->id() : 0);
                    if ($siteId > 0 && $focusKeyword !== '' && $actorId > 0) {
                        KeywordFocusAttach::syncMainKeyword($article, $siteId, $actorId, $focusKeyword);
                    } elseif ($focusKeyword !== '') {
                        $article->articleMetas()->updateOrCreate(
                            ['meta_key' => 'seo_focus_keyword'],
                            ['meta_value' => $focusKeyword],
                        );
                    }

                    if ($normalizedSlug !== '') {
                        $previous = trim((string) ($article->slug ?? ''));
                        if ($normalizedSlug !== $previous) {
                            $article->update(['slug' => $normalizedSlug]);
                            $this->syncFlags->markLocalEditPending($article->fresh() ?? $article);
                        }
                    }
                });
            });
        } catch (\Throwable $exception) {
            return ActionResult::failure('seo_meta_update_failed', $exception->getMessage());
        }

        $fresh = $article->fresh() ?? $article;
        $dispatchScoring = ! array_key_exists('dispatch_scoring', $input)
            || filter_var($input['dispatch_scoring'], FILTER_VALIDATE_BOOLEAN);

        if ($dispatchScoring) {
            $this->scoringQueue->dispatchForArticle($fresh, force: true);
        }

        return ActionResult::success(
            output: [
                'article_id' => $articleId,
                'focus_keyword' => $focusKeyword,
                'meta_description' => $metaDescription,
                'slug' => $normalizedSlug !== '' ? $normalizedSlug : (string) ($fresh->slug ?? ''),
                'seo_analysis_pending' => $dispatchScoring,
            ],
            events: [
                ActionSupport::articleEvent('article.seo_meta_updated', $context, $articleId, [
                    'changed_fields' => array_values(array_filter([
                        $focusKeyword !== '' ? 'focus_keyword' : null,
                        'meta_description',
                        $normalizedSlug !== '' ? 'slug' : null,
                    ])),
                ]),
            ],
            changed: ['seo_meta'],
        );
    }
}
