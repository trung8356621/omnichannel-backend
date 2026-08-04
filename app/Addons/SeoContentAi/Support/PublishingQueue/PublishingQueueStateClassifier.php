<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Support\PublishingQueue;

/**
 * Publishing Queue presentation states — Summary ≡ List.
 *
 * Unscheduled → Scheduled → Publishing → Published | Failed
 *
 * Publishing = processing only (runner claimed + publisher op running).
 * Due schedule chưa claim = Scheduled, không phải Publishing.
 */
final class PublishingQueueStateClassifier
{
    public const UNSCHEDULED = 'unscheduled';

    public const SCHEDULED = 'scheduled';

    public const PUBLISHING = 'publishing';

    public const PUBLISHED = 'published';

    public const FAILED = 'failed';

    /**
     * @param  array<string, mixed>  $row
     * @return array{state: string, label: string}
     */
    public static function classify(array $row): array
    {
        if (PublishingQueuePublishedDefinition::matches($row)) {
            return ['state' => self::PUBLISHED, 'label' => 'Published'];
        }
        if (PublishingQueueFailedDefinition::matches($row)) {
            return ['state' => self::FAILED, 'label' => 'Failed'];
        }
        if (PublishingQueuePublishingDefinition::matches($row)) {
            return ['state' => self::PUBLISHING, 'label' => 'Publishing'];
        }
        if (PublishingQueueScheduledDefinition::matches($row)) {
            return ['state' => self::SCHEDULED, 'label' => 'Scheduled'];
        }

        return ['state' => self::UNSCHEDULED, 'label' => 'Unscheduled'];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, int>
     */
    public static function countSummary(array $rows): array
    {
        $counts = [
            'unscheduled' => 0,
            'scheduled' => 0,
            'publishing' => 0,
            'published' => 0,
            'failed' => 0,
            'total' => count($rows),
        ];
        foreach ($rows as $row) {
            $state = self::classify($row)['state'];
            if (isset($counts[$state])) {
                $counts[$state]++;
            }
        }

        return $counts;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function matchesFilter(array $row, string $filter): bool
    {
        $filter = strtolower(trim($filter));
        if ($filter === '' || $filter === 'all') {
            return true;
        }

        return self::classify($row)['state'] === $filter;
    }
}
