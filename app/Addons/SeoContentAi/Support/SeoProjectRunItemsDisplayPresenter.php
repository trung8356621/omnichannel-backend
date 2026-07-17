<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Support;

/**
 * Gom nhiều raw run.items (và pending shadow) thành đúng 1 hàng hiển thị / task|article.
 * Không ghi đè JSON lịch sử — chỉ dùng ở view layer.
 */
final class SeoProjectRunItemsDisplayPresenter
{
    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    public function consolidate(array $items): array
    {
        /** @var list<array<string, mixed>> $normalized */
        $normalized = [];

        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                continue;
            }

            $row = $this->normalizeIds($item);
            $row['_src_index'] = (int) $index;
            $normalized[] = $row;
        }

        if ($normalized === []) {
            return [];
        }

        /** @var array<int, int> $parent */
        $parent = [];
        foreach (array_keys($normalized) as $index) {
            $parent[$index] = $index;
        }

        $find = function (int $index) use (&$parent, &$find): int {
            $root = $parent[$index] ?? $index;
            if ($root !== $index) {
                $parent[$index] = $find($root);
            }

            return $parent[$index] ?? $index;
        };

        $union = function (int $left, int $right) use (&$parent, $find): void {
            $leftRoot = $find($left);
            $rightRoot = $find($right);
            if ($leftRoot !== $rightRoot) {
                $parent[$rightRoot] = $leftRoot;
            }
        };

        /** @var array<string, int> $tokenOwners */
        $tokenOwners = [];

        foreach ($normalized as $index => $item) {
            foreach ($this->identityTokens($item) as $token) {
                if (isset($tokenOwners[$token])) {
                    $union($tokenOwners[$token], $index);
                    continue;
                }

                $tokenOwners[$token] = $index;
            }
        }

        /** @var array<int, list<array<string, mixed>>> $groups */
        $groups = [];
        foreach ($normalized as $index => $item) {
            $groups[$find($index)][] = $item;
        }

        return array_values(array_map(
            fn (array $group): array => $this->mergeGroup($group),
            $groups,
        ));
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function normalizeIds(array $item): array
    {
        foreach (['task_id', 'article_id', 'retry_task_id', 'retry_count'] as $key) {
            if (array_key_exists($key, $item) && is_numeric($item[$key])) {
                $item[$key] = (int) $item[$key];
            }
        }

        return $item;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return list<string>
     */
    private function identityTokens(array $item): array
    {
        $tokens = [];
        $taskId = (int) ($item['task_id'] ?? 0);
        $articleId = (int) ($item['article_id'] ?? 0);
        $retryTaskId = (int) ($item['retry_task_id'] ?? 0);

        if ($taskId > 0) {
            $tokens[] = 'task:'.$taskId;
        }

        if ($articleId > 0) {
            $tokens[] = 'article:'.$articleId;
        }

        if ($retryTaskId > 0) {
            // Liên kết pending/retry task (task_id khác) về cùng nhóm.
            $tokens[] = 'task:'.$retryTaskId;
            $tokens[] = 'retry:'.$retryTaskId;
        }

        if ($tokens !== []) {
            return $tokens;
        }

        $payload = [
            (string) ($item['type'] ?? ''),
            mb_strtolower(trim((string) ($item['source_content'] ?? ''))),
            (string) ($item['post_type'] ?? ''),
            (string) ($item['status'] ?? ''),
            (string) ($item['last_run_at'] ?? ''),
            (string) ($item['message'] ?? ''),
        ];

        return ['legacy:'.sha1(implode('|', $payload))];
    }

    /**
     * @param  list<array<string, mixed>>  $group
     * @return array<string, mixed>
     */
    private function mergeGroup(array $group): array
    {
        $latest = $this->pickLatestAttempt($group);
        $rerunCount = $this->resolveRerunCount($group);
        $canonicalTaskId = $this->pickCanonicalTaskId($group, $latest);
        $articleId = $this->pickArticleId($group, $latest);
        $lastRunAt = $this->pickLatestRunAt($group);

        $merged = $latest;
        $merged['task_id'] = $canonicalTaskId;

        if ($articleId > 0) {
            $merged['article_id'] = $articleId;
            if (filled($latest['article_edit_url'] ?? null)) {
                $merged['article_edit_url'] = $latest['article_edit_url'];
            } else {
                foreach ($group as $item) {
                    if ((int) ($item['article_id'] ?? 0) === $articleId && filled($item['article_edit_url'] ?? null)) {
                        $merged['article_edit_url'] = $item['article_edit_url'];
                        break;
                    }
                }
            }
        }

        $merged['retry_count'] = $rerunCount;
        if ($lastRunAt !== null && $lastRunAt !== '') {
            $merged['last_run_at'] = $lastRunAt;
        }

        $retryTaskId = $this->pickRetryTaskId($group, $canonicalTaskId);
        if ($retryTaskId > 0) {
            $merged['retry_task_id'] = $retryTaskId;
        } else {
            unset($merged['retry_task_id']);
        }

        $merged['message'] = $this->buildDisplayMessage($latest, $rerunCount);
        $merged['raw_attempt_count'] = count($group);
        unset($merged['_src_index']);

        return $merged;
    }

    /**
     * Latest meaningful attempt: ưu tiên bản đã chạy (success/failed/manual) theo last_run_at.
     * Không lấy “best” lịch sử — latest failed sau success vẫn hiện failed.
     *
     * @param  list<array<string, mixed>>  $group
     * @return array<string, mixed>
     */
    private function pickLatestAttempt(array $group): array
    {
        $executed = array_values(array_filter(
            $group,
            static fn (array $item): bool => in_array((string) ($item['status'] ?? ''), ['success', 'failed', 'manual'], true),
        ));

        $pool = $executed !== [] ? $executed : $group;

        usort($pool, function (array $left, array $right): int {
            $leftTs = $this->runAtTimestamp($left);
            $rightTs = $this->runAtTimestamp($right);
            if ($leftTs !== $rightTs) {
                return $rightTs <=> $leftTs;
            }

            return ((int) ($right['_src_index'] ?? 0)) <=> ((int) ($left['_src_index'] ?? 0));
        });

        return $pool[0];
    }

    /**
     * @param  list<array<string, mixed>>  $group
     * @param  array<string, mixed>  $latest
     */
    private function pickCanonicalTaskId(array $group, array $latest): int
    {
        foreach ($group as $item) {
            $taskId = (int) ($item['task_id'] ?? 0);
            $retryTaskId = (int) ($item['retry_task_id'] ?? 0);
            if ($taskId > 0 && $retryTaskId > 0) {
                return $taskId;
            }
        }

        $latestTaskId = (int) ($latest['task_id'] ?? 0);
        if ($latestTaskId > 0) {
            $memberTaskIds = [];
            foreach ($group as $item) {
                $id = (int) ($item['task_id'] ?? 0);
                if ($id > 0) {
                    $memberTaskIds[$id] = true;
                }
            }

            $isOnlyRetryShadow = false;
            foreach ($group as $item) {
                if ((int) ($item['retry_task_id'] ?? 0) === $latestTaskId && (int) ($item['task_id'] ?? 0) > 0) {
                    $isOnlyRetryShadow = true;
                    break;
                }
            }

            if (! $isOnlyRetryShadow || count($memberTaskIds) === 1) {
                return $latestTaskId;
            }
        }

        $ids = [];
        foreach ($group as $item) {
            $taskId = (int) ($item['task_id'] ?? 0);
            if ($taskId > 0) {
                $ids[] = $taskId;
            }
        }

        return $ids !== [] ? min($ids) : 0;
    }

    /**
     * @param  list<array<string, mixed>>  $group
     * @param  array<string, mixed>  $latest
     */
    private function pickArticleId(array $group, array $latest): int
    {
        $latestArticleId = (int) ($latest['article_id'] ?? 0);
        if ($latestArticleId > 0) {
            return $latestArticleId;
        }

        foreach ($group as $item) {
            $articleId = (int) ($item['article_id'] ?? 0);
            if ($articleId > 0) {
                return $articleId;
            }
        }

        return 0;
    }

    /**
     * @param  list<array<string, mixed>>  $group
     */
    private function pickLatestRunAt(array $group): ?string
    {
        $bestTs = 0;
        $bestRaw = null;

        foreach ($group as $item) {
            $raw = trim((string) ($item['last_run_at'] ?? ''));
            if ($raw === '') {
                continue;
            }

            $ts = $this->runAtTimestamp($item);
            if ($ts >= $bestTs) {
                $bestTs = $ts;
                $bestRaw = $raw;
            }
        }

        return $bestRaw;
    }

    /**
     * @param  list<array<string, mixed>>  $group
     */
    private function pickRetryTaskId(array $group, int $canonicalTaskId): int
    {
        foreach ($group as $item) {
            $retryTaskId = (int) ($item['retry_task_id'] ?? 0);
            if ($retryTaskId > 0 && $retryTaskId !== $canonicalTaskId) {
                return $retryTaskId;
            }
        }

        return 0;
    }

    /**
     * retry_count trong storage = số lần chạy lại thêm (không tính lần đầu).
     * Dùng trực tiếp khi đã có; fallback đếm attempt theo last_run_at khác nhau.
     *
     * @param  list<array<string, mixed>>  $group
     */
    private function resolveRerunCount(array $group): int
    {
        $maxStored = 0;
        foreach ($group as $item) {
            $maxStored = max($maxStored, (int) ($item['retry_count'] ?? 0));
        }

        if ($maxStored > 0) {
            return $maxStored;
        }

        $uniqueRunAts = [];
        foreach ($group as $item) {
            $status = (string) ($item['status'] ?? '');
            if (! in_array($status, ['success', 'failed', 'manual'], true)) {
                continue;
            }

            $raw = trim((string) ($item['last_run_at'] ?? ''));
            if ($raw === '') {
                $uniqueRunAts['idx:'.(int) ($item['_src_index'] ?? 0)] = true;
                continue;
            }

            $uniqueRunAts[$raw] = true;
        }

        return max(0, count($uniqueRunAts) - 1);
    }

    /**
     * @param  array<string, mixed>  $latest
     */
    private function buildDisplayMessage(array $latest, int $rerunCount): string
    {
        $message = trim((string) ($latest['message'] ?? ''));
        $message = $this->stripRerunPhrases($message);

        if ($rerunCount <= 0) {
            return $message;
        }

        $rerunPhrase = (string) trans_choice(
            'seo-content-ai::filament.projects.run_item_rerun_count_inline',
            $rerunCount,
            ['count' => $rerunCount],
        );

        if ($message === '') {
            return $rerunPhrase;
        }

        if (preg_match('/^(.+?[.。])(\s*)(.*)$/u', $message, $matches) === 1) {
            $tail = trim((string) ($matches[3] ?? ''));
            if ($tail === '') {
                return rtrim((string) $matches[1]).' '.$rerunPhrase;
            }

            return rtrim((string) $matches[1]).' '.$rerunPhrase.' '.$tail;
        }

        return $message.' '.$rerunPhrase;
    }

    private function stripRerunPhrases(string $message): string
    {
        $stripped = preg_replace(
            '/\s*(?:Đã\s+)?[Cc]hạy lại\s+\d+\s+lần\.?/u',
            '',
            $message,
        ) ?? $message;

        $stripped = preg_replace(
            '/\s*(?:Rerun(?:ned)?|Retried)\s+\d+\s+times?\.?/iu',
            '',
            $stripped,
        ) ?? $stripped;

        $stripped = preg_replace('/\s{2,}/u', ' ', $stripped) ?? $stripped;

        return trim($stripped);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function runAtTimestamp(array $item): int
    {
        $raw = trim((string) ($item['last_run_at'] ?? ''));
        if ($raw === '') {
            return 0;
        }

        $ts = strtotime($raw);

        return is_int($ts) ? $ts : 0;
    }
}
