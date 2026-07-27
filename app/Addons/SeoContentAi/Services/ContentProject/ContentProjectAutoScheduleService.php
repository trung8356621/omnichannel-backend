<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\ContentProject;

use App\Addons\SeoContentAi\Models\SeoProject;
use App\Addons\SeoContentAi\Models\SeoProjectTask;
use Carbon\Carbon;
use InvalidArgumentException;
use RuntimeException;

/**
 * Auto-schedule hàng loạt item theo pattern (interval / per-day / random windows).
 */
final class ContentProjectAutoScheduleService
{
    public function __construct(
        private readonly ContentProjectPublishingQueueService $queue,
    ) {}

    /**
     * @param  list<int>  $taskIds
     * @param  array{
     *     mode: 'interval'|'per_day'|'random_windows',
     *     start_at: string|Carbon,
     *     interval_minutes?: int,
     *     per_day?: int,
     *     day_start?: string,
     *     day_end?: string,
     *     windows?: list<array{start: string, end: string}>,
     * }  $options
     * @return array{scheduled: int, slots: list<string>}
     */
    public function schedule(SeoProject $project, array $taskIds, array $options): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map(static fn ($id): int => (int) $id, $taskIds),
            static fn (int $id): bool => $id > 0,
        )));

        if ($ids === []) {
            $ids = SeoProjectTask::query()
                ->where('project_id', (int) $project->getKey())
                ->active()
                ->where('article_id', '>', 0)
                ->where(function ($q): void {
                    $q->whereNull('publish_queue_status')
                        ->orWhereIn('publish_queue_status', ['none', 'failed', 'cancelled', 'skipped']);
                })
                ->whereNull('scheduled_publish_at')
                ->orderBy('id')
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all();
        }

        if ($ids === []) {
            return ['scheduled' => 0, 'slots' => []];
        }

        $mode = (string) ($options['mode'] ?? 'interval');
        $startAt = $options['start_at'] instanceof Carbon
            ? $options['start_at']->copy()
            : Carbon::parse((string) $options['start_at']);

        $slots = match ($mode) {
            'interval' => $this->buildIntervalSlots(
                $startAt,
                count($ids),
                max(1, (int) ($options['interval_minutes'] ?? 15)),
            ),
            'per_day' => $this->buildPerDaySlots(
                $startAt,
                count($ids),
                max(1, (int) ($options['per_day'] ?? 3)),
                (string) ($options['day_start'] ?? '09:00'),
                (string) ($options['day_end'] ?? '17:00'),
            ),
            'random_windows' => $this->buildRandomWindowSlots(
                $startAt,
                count($ids),
                is_array($options['windows'] ?? null) ? $options['windows'] : [
                    ['start' => '08:00', 'end' => '11:30'],
                    ['start' => '14:00', 'end' => '17:00'],
                ],
            ),
            default => throw new InvalidArgumentException('Auto Schedule mode không hợp lệ.'),
        };

        $scheduled = 0;
        foreach ($ids as $index => $taskId) {
            $at = $slots[$index] ?? null;
            if (! $at instanceof Carbon) {
                break;
            }

            $scheduled += $this->queue->schedule($project, [$taskId], $at);
        }

        return [
            'scheduled' => $scheduled,
            'slots' => array_map(
                static fn (Carbon $c): string => $c->toIso8601String(),
                $slots,
            ),
        ];
    }

    /**
     * @return list<Carbon>
     */
    private function buildIntervalSlots(Carbon $start, int $count, int $intervalMinutes): array
    {
        $slots = [];
        $cursor = $start->copy();
        for ($i = 0; $i < $count; $i++) {
            $slots[] = $cursor->copy();
            $cursor->addMinutes($intervalMinutes);
        }

        return $slots;
    }

    /**
     * @return list<Carbon>
     */
    private function buildPerDaySlots(
        Carbon $startDay,
        int $count,
        int $perDay,
        string $dayStart,
        string $dayEnd,
    ): array {
        [$sh, $sm] = $this->parseHm($dayStart);
        [$eh, $em] = $this->parseHm($dayEnd);

        $slots = [];
        $day = $startDay->copy()->startOfDay();
        while (count($slots) < $count) {
            $windowStart = $day->copy()->setTime($sh, $sm);
            $windowEnd = $day->copy()->setTime($eh, $em);
            if ($windowEnd->lte($windowStart)) {
                throw new RuntimeException('Khung giờ per_day không hợp lệ.');
            }

            $spanMinutes = max(1, $windowStart->diffInMinutes($windowEnd));
            $step = max(1, intdiv($spanMinutes, max(1, $perDay)));

            for ($i = 0; $i < $perDay && count($slots) < $count; $i++) {
                $slots[] = $windowStart->copy()->addMinutes($i * $step);
            }

            $day->addDay();
        }

        return $slots;
    }

    /**
     * @param  list<array{start: string, end: string}>  $windows
     * @return list<Carbon>
     */
    private function buildRandomWindowSlots(Carbon $startDay, int $count, array $windows): array
    {
        if ($windows === []) {
            throw new InvalidArgumentException('Cần ít nhất 1 khung giờ.');
        }

        $slots = [];
        $day = $startDay->copy()->startOfDay();
        $guard = 0;

        while (count($slots) < $count && $guard < 5000) {
            $guard++;
            foreach ($windows as $window) {
                if (count($slots) >= $count) {
                    break;
                }

                [$sh, $sm] = $this->parseHm((string) ($window['start'] ?? '08:00'));
                [$eh, $em] = $this->parseHm((string) ($window['end'] ?? '11:30'));
                $from = $day->copy()->setTime($sh, $sm);
                $to = $day->copy()->setTime($eh, $em);
                if ($to->lte($from)) {
                    continue;
                }

                $minutes = $from->diffInMinutes($to);
                $offset = random_int(0, max(0, $minutes));
                $slots[] = $from->copy()->addMinutes($offset);
            }
            $day->addDay();
        }

        usort($slots, static fn (Carbon $a, Carbon $b): int => $a <=> $b);

        return array_slice($slots, 0, $count);
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function parseHm(string $value): array
    {
        if (! preg_match('/^(\d{1,2}):(\d{2})$/', trim($value), $m)) {
            throw new InvalidArgumentException("Giờ không hợp lệ: {$value}");
        }

        return [(int) $m[1], (int) $m[2]];
    }
}
