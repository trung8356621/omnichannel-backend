<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Support;

/**
 * Parse JSON bình luận/review từ output AI (mảng object hoặc bọc trong markdown).
 */
final class CommentReviewPayloadParser
{
    /**
     * @return list<array<string, mixed>>
     */
    public function parse(string $raw): array
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return [];
        }

        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/i', $trimmed, $matches)) {
            $trimmed = trim($matches[1]);
        }

        $decoded = json_decode($trimmed, true);
        if (! is_array($decoded)) {
            return [];
        }

        if ($this->isListOfItems($decoded)) {
            return $this->normalizeList($decoded);
        }

        foreach (['comments', 'reviews', 'items', 'data'] as $key) {
            if (isset($decoded[$key]) && is_array($decoded[$key]) && $this->isListOfItems($decoded[$key])) {
                return $this->normalizeList($decoded[$key]);
            }
        }

        return [];
    }

    /**
     * @param  array<int, mixed>  $list
     * @return list<array<string, mixed>>
     */
    private function normalizeList(array $list): array
    {
        $items = [];

        foreach ($list as $row) {
            if (! is_array($row)) {
                continue;
            }

            $content = trim((string) (
                $row['comment']
                ?? $row['content']
                ?? $row['review']
                ?? $row['noi_dung']
                ?? ''
            ));

            if ($content === '') {
                continue;
            }

            $author = trim((string) (
                $row['Họ và tên']
                ?? $row['ho_va_ten']
                ?? $row['author']
                ?? $row['author_name']
                ?? $row['name']
                ?? 'Khách'
            ));

            $email = trim((string) (
                $row['Email']
                ?? $row['email']
                ?? $row['author_email']
                ?? ''
            ));

            $rating = null;
            foreach (['star_ranking', 'rating', 'stars', 'star'] as $ratingKey) {
                if (isset($row[$ratingKey]) && is_numeric($row[$ratingKey])) {
                    $rating = (int) $row[$ratingKey];
                    break;
                }
            }

            $item = [
                'content' => $content,
                'author' => $author,
                'email' => $email,
            ];

            if ($rating !== null) {
                $item['rating'] = max(1, min(5, $rating));
            }

            $items[] = $item;
        }

        return $items;
    }

    /**
     * @param  array<int, mixed>  $list
     */
    private function isListOfItems(array $list): bool
    {
        if ($list === []) {
            return false;
        }

        return array_is_list($list);
    }
}
