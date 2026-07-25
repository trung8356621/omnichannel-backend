@php
    /** @var array<string, mixed> $summary */
    $summary = is_array($summary ?? null) ? $summary : [];

    $domainName = trim((string) ($summary['domain_name'] ?? ''));
    $month = $summary['month'] ?? null;
    $year = $summary['year'] ?? null;
    $period = ($month !== null && $year !== null)
        ? sprintf('%02d/%s', (int) $month, (int) $year)
        : '—';

    $total = (int) ($summary['total_articles'] ?? 0);
    $completed = (int) ($summary['completed_articles'] ?? 0);
    $approved = (int) ($summary['approved_articles'] ?? 0);
    $synced = (int) ($summary['synced_articles'] ?? 0);
    $incomplete = (int) ($summary['incomplete_articles'] ?? max(0, $total - $completed));
    $unapproved = (int) ($summary['unapproved_articles'] ?? max(0, $total - $approved));
    $unsynced = (int) ($summary['unsynced_articles'] ?? max(0, $total - $synced));
    $failed = (int) ($summary['failed_articles'] ?? 0);

    $showWarning = $unapproved > 0 || $unsynced > 0 || $failed > 0;
@endphp

<div class="prose prose-sm max-w-none dark:prose-invert">
    <ul class="my-0 list-disc space-y-1 pl-5">
        <li><strong>Tên miền:</strong> {{ $domainName !== '' ? e($domainName) : '—' }}</li>
        <li><strong>Tháng/Năm:</strong> {{ e($period) }}</li>
        <li><strong>Tổng bài:</strong> {{ $total }}</li>
        <li><strong>Hoàn thành:</strong> {{ $completed }}</li>
        <li><strong>Đã duyệt:</strong> {{ $approved }}</li>
        <li><strong>Đã đồng bộ:</strong> {{ $synced }}</li>
        <li><strong>Chưa hoàn thành:</strong> {{ $incomplete }}</li>
        <li><strong>Chưa duyệt:</strong> {{ $unapproved }}</li>
        <li><strong>Chưa đồng bộ:</strong> {{ $unsynced }}</li>
        <li><strong>Bài lỗi:</strong> {{ $failed }}</li>
    </ul>

    @if ($showWarning)
        <div class="mt-4 rounded-lg border border-warning-300 bg-warning-50 px-3 py-2 text-sm text-warning-900 not-prose dark:border-warning-500/40 dark:bg-warning-500/10 dark:text-warning-100">
            Dự án còn bài chưa duyệt, chưa đồng bộ hoặc lỗi — vẫn có thể lưu trữ. Snapshot sẽ giữ nguyên trạng thái hiện tại.
        </div>
    @endif
</div>
