<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\ContentProject\Application;

use Filament\Notifications\Notification;

/**
 * Filament map ContentProjectActionResult → Notification.
 * Publishing Queue: không success toast — chỉ danger/warning khi fail/confirm.
 */
final class ContentProjectActionResultNotifier
{
    public function send(ContentProjectActionResult $result, bool $allowSuccessToast = false): void
    {
        if ($result->success && ! $allowSuccessToast) {
            return;
        }

        $message = $this->mapBusinessMessage($result->message);
        $notification = Notification::make()
            ->title($this->mapTitle($result))
            ->body($message);

        if ($result->success) {
            $notification->success();
        } elseif ($result->code === ContentProjectActionCodes::CONFIRMATION_REQUIRED
            || $result->code === ContentProjectActionCodes::PREVIEW_READY
        ) {
            $notification->warning();
        } else {
            $notification->danger();
        }

        $notification->send();
    }

    private function mapTitle(ContentProjectActionResult $result): string
    {
        if (str_contains($result->message, 'lifecycle.invalid_transition: processing')
            || str_contains($result->message, 'publishing.busy_cannot_reschedule')
        ) {
            return 'Không thể đổi lịch';
        }
        if (str_contains($result->message, 'stale_processing')
            || str_contains($result->code, 'recover')
        ) {
            return 'Khôi phục Publishing';
        }

        return $result->code;
    }

    private function mapBusinessMessage(string $message): string
    {
        if (str_contains($message, 'lifecycle.invalid_transition: processing → cancelled')
            || str_contains($message, 'lifecycle.invalid_transition: processing')
        ) {
            return 'Bài đang được xuất bản nên không thể đổi lịch.';
        }
        if (str_contains($message, 'publishing.busy_cannot_reschedule')) {
            return 'Bài đang được xuất bản nên không thể đổi lịch.';
        }
        if (str_contains($message, 'stale_processing')) {
            return 'Tiến trình xuất bản đã quá hạn. Hãy khôi phục trạng thái trước.';
        }
        if (str_contains($message, 'Không có bài chưa lên lịch phù hợp')) {
            return 'Không có bài chưa lên lịch phù hợp';
        }

        // Strip technical prefix codes when body already has business text after ": ".
        if (preg_match('/^publishing\.[a-z_]+:\s*(.+)$/u', $message, $m) === 1) {
            return $m[1];
        }

        return $message;
    }
}
