<?php

declare(strict_types=1);

namespace App\Addons\WpHeadless\Filament\Pages;

use App\Addons\WpHeadless\Models\WpHeadlessSite;
use App\Addons\WpHeadless\Services\WpHeadlessSyncService;
use App\Models\Site;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

class WpHeadlessSitePage extends Page
{
    protected static ?string $slug = 'wp-headless/site';

    protected static string $view = 'wp-headless::filament.pages.wp-headless-site';

    protected static bool $shouldRegisterNavigation = false;

    public ?int $siteId = null;

    public ?Site $site = null;

    public ?WpHeadlessSite $wpHeadlessSite = null;

    public bool $syncing = false;

    public function mount(): void
    {
        $this->siteId = (int) request()->query('site_id');
        if ($this->siteId <= 0) {
            Notification::make()
                ->title('Thiếu site_id')
                ->body('Vui lòng truyền site_id trong URL: /wp-headless/site?site_id=...')
                ->danger()
                ->send();
            $this->redirect('/admin/wp-headless/manage');
            return;
        }

        $this->site = Site::find($this->siteId);
        if (!$this->site) {
            Notification::make()
                ->title('Site không tồn tại')
                ->danger()
                ->send();
            $this->redirect('/admin/wp-headless/manage');
            return;
        }

        $this->wpHeadlessSite = WpHeadlessSite::find($this->siteId);
    }

    public function getTitle(): string|Htmlable
    {
        return 'WP Headless – Site ' . ($this->site?->domain ?? (string) $this->siteId);
    }

    public function getHeading(): string|Htmlable
    {
        return $this->getTitle();
    }

    public function syncSiteData(): void
    {
        if (!$this->site) {
            return;
        }
        $this->syncing = true;
        try {
            $result = app(WpHeadlessSyncService::class)->sync($this->site);
            if ($result['success'] ?? false) {
                $this->wpHeadlessSite = WpHeadlessSite::find($this->site->id);
                Notification::make()
                    ->title('Đồng bộ thành công')
                    ->success()
                    ->send();
            } else {
                Notification::make()
                    ->title($result['message'] ?? 'Đồng bộ thất bại')
                    ->danger()
                    ->send();
            }
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Lỗi: ' . $e->getMessage())
                ->danger()
                ->send();
        } finally {
            $this->syncing = false;
        }
    }

    public function hasWpHeadlessSite(): bool
    {
        return $this->wpHeadlessSite !== null;
    }
}
