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
use App\Addons\SeoContentAi\Services\ArticleEditorSeoMetaService;
use Illuminate\Support\Facades\DB;

/**
 * Local SEO meta via ArticleEditorSeoMetaService::persist — Action owns events.
 */
final class UpdateArticleSeoMetaAction implements BusinessAction
{
    public function __construct(
        private readonly ArticleEditorSeoMetaService $seoMeta,
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
            emittedEvents: ['article.seo_meta_updated', 'article.content_updated'],
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
        $slug = trim((string) ($input['slug'] ?? ''));

        try {
            [$fresh, $focusKeyword, $metaDescription, $normalizedSlug] = ActionSupport::withArticleLock(
                $articleId,
                function () use ($article, $focusKeyword, $metaDescription, $slug): array {
                    return DB::connection('omi_seo_ai')->transaction(function () use ($article, $focusKeyword, $metaDescription, $slug): array {
                        return $this->seoMeta->persist(
                            $article->fresh() ?? $article,
                            $focusKeyword,
                            $metaDescription,
                            $slug,
                        );
                    });
                },
            );
        } catch (\Throwable $exception) {
            return ActionResult::failure('seo_meta_update_failed', $exception->getMessage());
        }

        return ActionResult::success(
            output: [
                'article_id' => $articleId,
                'focus_keyword' => $focusKeyword,
                'meta_description' => $metaDescription,
                'slug' => $normalizedSlug !== '' ? $normalizedSlug : (string) ($fresh->slug ?? ''),
                'seo_analysis_pending' => false,
            ],
            events: [
                ActionSupport::articleEvent('article.seo_meta_updated', $context, $articleId, [
                    'changed_fields' => array_values(array_filter([
                        $focusKeyword !== '' ? 'focus_keyword' : null,
                        'meta_description',
                        $normalizedSlug !== '' ? 'slug' : null,
                    ])),
                ]),
                ActionSupport::articleEvent('article.content_updated', $context, $articleId, []),
            ],
            changed: ['seo_meta'],
        );
    }
}
