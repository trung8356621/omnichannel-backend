<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\ContentProject\Application;

use Filament\Notifications\Notification;

/**
 * Filament map ContentProjectActionResult → Notification.
 */
final class ContentProjectActionResultNotifier
{
    public function send(ContentProjectActionResult $result): void
    {
        $notification = Notification::make()
            ->title($result->code)
            ->body($result->message);

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
}
