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
     *     mode: 'interval'|'per_day'|'random_windows'|'project_month'|'quick',
     *     start_at?: string|Carbon,
     *     interval_minutes?: int,
     *     per_day?: int,
     *     day_start?: string,
     *     day_end?: string,
     *     windows?: list<array{start: string, end: string}>,
     *     days?: int,
     *     start_time?: string,
     *     end_time?: string,
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
            $q = SeoProjectTask::query()
                ->where('project_id', (int) $project->getKey())
                ->active()
                ->where('article_id', '>', 0)
                ->whereNull('scheduled_publish_at')
                ->where(function ($q): void {
                    $q->whereNull('publish_queue_status')
                        ->orWhereIn('publish_queue_status', ['none', 'failed', 'cancelled', 'skipped']);
                })
                ->orderBy('id');
            if (\Illuminate\Support\Facades\Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'publishing_queued_at')) {
                $q->whereNotNull('publishing_queued_at');
            }
            $ids = $q->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        }

        if ($ids === []) {
            return ['scheduled' => 0, 'slots' => []];
        }

        $mode = (string) ($options['mode'] ?? 'interval');
        $slots = match ($mode) {
            'interval' => $this->buildIntervalSlots(
                $options['start_at'] instanceof Carbon
                    ? $options['start_at']->copy()
                    : Carbon::parse((string) ($options['start_at'] ?? now()->toIso8601String())),
                count($ids),
                max(1, (int) ($options['interval_minutes'] ?? 15)),
            ),
            'per_day' => $this->buildPerDaySlots(
                $options['start_at'] instanceof Carbon
                    ? $options['start_at']->copy()
                    : Carbon::parse((string) ($options['start_at'] ?? now()->toIso8601String())),
                count($ids),
                max(1, (int) ($options['per_day'] ?? 3)),
                (string) ($options['day_start'] ?? '09:00'),
                (string) ($options['day_end'] ?? '17:00'),
            ),
            'random_windows' => $this->buildRandomWindowSlots(
                $options['start_at'] instanceof Carbon
                    ? $options['start_at']->copy()
                    : Carbon::parse((string) ($options['start_at'] ?? now()->toIso8601String())),
                count($ids),
                is_array($options['windows'] ?? null) ? $options['windows'] : [
                    ['start' => '08:00', 'end' => '11:30'],
                    ['start' => '14:00', 'end' => '17:00'],
                ],
            ),
            'project_month' => $this->buildProjectMonthSlots(
                $project,
                count($ids),
                (string) ($options['day_start'] ?? '09:00'),
                (string) ($options['day_end'] ?? '17:00'),
            ),
            'quick' => $this->buildQuickModeSlots(
                count($ids),
                max(1, (int) ($options['days'] ?? 1)),
                (string) ($options['start_time'] ?? $options['day_start'] ?? '08:00'),
                (string) ($options['end_time'] ?? $options['day_end'] ?? '17:00'),
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
     * Auto Mode — distribute across remaining days of Content Project month.
     *
     * @return list<Carbon>
     */
    private function buildProjectMonthSlots(
        SeoProject $project,
        int $count,
        string $dayStart,
        string $dayEnd,
    ): array {
        $month = $project->month;
        if ($month === null) {
            throw new RuntimeException('Project month missing — use Quick Mode or custom range.');
        }

        $monthStart = $month->copy()->startOfMonth()->startOfDay();
        $monthEnd = $month->copy()->endOfMonth()->endOfDay();
        $today = now()->startOfDay();

        if ($monthEnd->lt($today)) {
            throw new RuntimeException('Project month already ended — use Quick Mode.');
        }

        $rangeStart = $monthStart->gt($today) ? $monthStart->copy() : $today->copy();
        $days = max(1, $rangeStart->diffInDays($monthEnd->copy()->startOfDay()) + 1);
        $perDay = max(1, (int) ceil($count / $days));

        return $this->buildPerDaySlots($rangeStart, $count, $perDay, $dayStart, $dayEnd);
    }

    /**
     * Quick Mode — deadline recovery (not Dev/Test). Even distribution + min interval.
     *
     * @return list<Carbon>
     */
    private function buildQuickModeSlots(
        int $count,
        int $days,
        string $startTime,
        string $endTime,
    ): array {
        $days = max(1, $days);
        $startDay = now()->startOfDay();
        if (now()->format('H:i') > $endTime) {
            $startDay->addDay();
        }

        $perDay = max(1, (int) ceil($count / $days));
        $slots = $this->buildPerDaySlots($startDay, $count, $perDay, $startTime, $endTime);

        // Enforce minimum interval (never identical timestamps).
        $minInterval = max(5, (int) floor((8 * 60) / max(1, $perDay)));
        $out = [];
        $prev = null;
        foreach ($slots as $slot) {
            $at = $slot->copy();
            if ($prev instanceof Carbon && $at->lte($prev)) {
                $at = $prev->copy()->addMinutes($minInterval);
            }
            if ($at->lt(now())) {
                $at = now()->copy()->addMinutes($minInterval);
            }
            $out[] = $at;
            $prev = $at;
        }

        return $out;
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
