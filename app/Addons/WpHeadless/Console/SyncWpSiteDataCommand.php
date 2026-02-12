<?php

declare(strict_types=1);

namespace App\Addons\WpHeadless\Console;

use App\Addons\WpHeadless\Services\WpHeadlessSyncService;
use App\Models\Site;
use Illuminate\Console\Command;

final class SyncWpSiteDataCommand extends Command
{
    protected $signature = 'wp-headless:sync-site {site_id : ID của site cần đồng bộ}';

    protected $description = 'Đồng bộ plugin/theme và CSS theo post_type từ WordPress vào site_meta';

    public function handle(WpHeadlessSyncService $syncService): int
    {
        $siteId = (int) $this->argument('site_id');
        $site = Site::find($siteId);

        if ($site === null) {
            $this->error("Site id {$siteId} không tồn tại.");
            return self::FAILURE;
        }

        $result = $syncService->sync($site);

        if (!$result['success']) {
            $this->error($result['message'] ?? 'Đồng bộ thất bại.');
            return self::FAILURE;
        }

        $this->info('Đồng bộ thành công: ' . implode(', ', $result['synced']));
        return self::SUCCESS;
    }
}
