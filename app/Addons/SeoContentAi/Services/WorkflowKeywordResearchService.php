<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Models\Keyword;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Support\TaskTestContext;
use App\Addons\SeoContentAi\Support\WorkflowExecutionState;

final class WorkflowKeywordResearchService
{
    /**
     * @param  array<string, list<string>>  $keywordGroups
     * @return array{parent_id: int, parent_phrase: string, children_count: int}
     */
    public function syncTopicCluster(SeoArticle $article, array $keywordGroups, ?string $focusPhrase = null): array
    {
        $focusPhrase = trim((string) ($focusPhrase ?? ''));
        if ($focusPhrase === '') {
            $focusPhrase = trim((string) $article->title);
        }

        if ($focusPhrase === '') {
            throw new \InvalidArgumentException('Không xác định được từ khóa chính cho cụm chủ đề.');
        }

        if ($keywordGroups === []) {
            throw new \InvalidArgumentException('Không có dữ liệu từ khóa ngữ nghĩa để lưu.');
        }

        $userId = (int) ($article->user_id ?: auth()->id());

        $parentKeyword = Keyword::query()->updateOrCreate(
            [
                'phrase' => $focusPhrase,
                'site_id' => $article->site_id,
                'type' => Keyword::TYPE_FOCUS,
            ],
            [
                'user_id' => $userId,
                'parent_id' => null,
            ],
        );

        $article->keywords()->syncWithoutDetaching([
            $parentKeyword->id => ['weight' => 1.0, 'is_main' => true],
        ]);

        $childrenCount = 0;

        foreach ($keywordGroups as $groupName => $keywordsList) {
            if (! is_array($keywordsList)) {
                continue;
            }

            foreach ($keywordsList as $keywordPhrase) {
                $phrase = trim((string) $keywordPhrase);
                if ($phrase === '') {
                    continue;
                }

                if (mb_strtolower($phrase) === mb_strtolower($focusPhrase)) {
                    continue;
                }

                $childKeyword = Keyword::query()->updateOrCreate(
                    [
                        'phrase' => $phrase,
                        'site_id' => $article->site_id,
                        'type' => Keyword::TYPE_FOCUS,
                    ],
                    [
                        'user_id' => $userId,
                        'parent_id' => $parentKeyword->id,
                        'metrics' => ['group' => (string) $groupName],
                    ],
                );

                $article->keywords()->syncWithoutDetaching([
                    $childKeyword->id => ['weight' => 0.5],
                ]);

                $childrenCount++;
            }
        }

        return [
            'parent_id' => (int) $parentKeyword->id,
            'parent_phrase' => $focusPhrase,
            'children_count' => $childrenCount,
        ];
    }

    public function resolveFocusPhrase(SeoArticle $article, TaskTestContext $context): string
    {
        $article->loadMissing('articleMetas');

        $fromMeta = $article->articleMetas->firstWhere('meta_key', 'seo_focus_keyword')?->meta_value;
        if (is_string($fromMeta) && trim($fromMeta) !== '') {
            return trim($fromMeta);
        }

        $fromContext = trim((string) ($context->variables['focus_keyword'] ?? ''));
        if ($fromContext !== '') {
            return $fromContext;
        }

        return trim((string) $article->title);
    }

    /**
     * @return array<string, list<string>>
     */
    public function keywordGroupsFromState(WorkflowExecutionState $state): array
    {
        $groups = $state->meta['seo_article_keywords'] ?? [];

        return is_array($groups) ? $groups : [];
    }

    public function shouldSyncKeywords(string $actionType, WorkflowExecutionState $state): bool
    {
        if ($actionType === 'save_vocabulary_research') {
            return true;
        }

        return $this->keywordGroupsFromState($state) !== [];
    }
}
