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
     * @return array{parent_id: int, parent_phrase: string, children_count: int, suggest_count: int}
     */
    public function syncTopicCluster(SeoArticle $article, array $keywordGroups, ?string $focusPhrase = null): array
    {
        [$clusterGroups, $relatedTopics] = $this->partitionKeywordGroups($keywordGroups);

        if ($clusterGroups === [] && $relatedTopics === []) {
            throw new \InvalidArgumentException('Không có dữ liệu từ khóa ngữ nghĩa để lưu.');
        }

        $userId = (int) ($article->user_id ?: auth()->id());
        $suggestCount = $this->syncRelatedTopicSuggestions($article, $relatedTopics, $userId);

        if ($clusterGroups === []) {
            return [
                'parent_id' => 0,
                'parent_phrase' => '',
                'children_count' => 0,
                'suggest_count' => $suggestCount,
            ];
        }

        $focusPhrase = trim((string) ($focusPhrase ?? ''));
        if ($focusPhrase === '') {
            throw new \InvalidArgumentException('Không xác định được từ khóa chính cho cụm chủ đề.');
        }

        if ($this->wordCount($focusPhrase) < 2) {
            throw new \InvalidArgumentException('Từ khóa chính quá rộng, cần ít nhất 2 từ để lưu Topic Cluster.');
        }

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

        foreach ($clusterGroups as $groupName => $keywordsList) {
            if (! is_array($keywordsList)) {
                continue;
            }

            foreach ($keywordsList as $keywordPhrase) {
                $phrase = trim((string) $keywordPhrase);
                if ($phrase === '') {
                    continue;
                }

                if ($this->wordCount($phrase) < 2
                    || $this->samePhrase($phrase, $focusPhrase)
                    || $this->samePhrase($phrase, (string) $article->title)) {
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
            'suggest_count' => $suggestCount,
        ];
    }

    /**
     * Tách nhóm Related topics (gợi ý bài mới) khỏi Topic Cluster.
     *
     * @param  array<string, list<string>>  $groups
     * @return array{0: array<string, list<string>>, 1: list<string>}
     */
    public function partitionKeywordGroups(array $groups): array
    {
        $clusterGroups = [];
        $relatedTopics = [];

        foreach ($groups as $groupName => $keywordsList) {
            if ($this->isRelatedTopicsGroup((string) $groupName)) {
                if (is_array($keywordsList)) {
                    foreach ($keywordsList as $phrase) {
                        $relatedTopics[] = (string) $phrase;
                    }
                }

                continue;
            }

            $clusterGroups[$groupName] = $keywordsList;
        }

        return [$clusterGroups, $relatedTopics];
    }

    /**
     * @param  list<string>  $phrases
     */
    private function syncRelatedTopicSuggestions(SeoArticle $article, array $phrases, int $userId): int
    {
        $count = 0;

        foreach ($phrases as $keywordPhrase) {
            $phrase = trim((string) $keywordPhrase);
            if ($phrase === '' || $this->wordCount($phrase) < 2) {
                continue;
            }

            if ($this->samePhrase($phrase, (string) $article->title)) {
                continue;
            }

            Keyword::query()->updateOrCreate(
                [
                    'phrase' => $phrase,
                    'site_id' => $article->site_id,
                    'type' => Keyword::TYPE_SUGGEST,
                ],
                [
                    'user_id' => $userId,
                    'parent_id' => null,
                    'metrics' => ['source' => 'vocabulary_related_topics'],
                ],
            );

            $count++;
        }

        return $count;
    }

    private function isRelatedTopicsGroup(string $groupName): bool
    {
        return mb_strtolower(trim($groupName)) === 'related topics';
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

        return '';
    }

    private function samePhrase(string $left, string $right): bool
    {
        $left = mb_strtolower(trim($left));
        $right = mb_strtolower(trim($right));

        return $left !== '' && $right !== '' && $left === $right;
    }

    private function wordCount(string $phrase): int
    {
        $phrase = trim((string) preg_replace('/\s+/u', ' ', $phrase));
        if ($phrase === '') {
            return 0;
        }

        return count(preg_split('/\s+/u', $phrase) ?: []);
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
