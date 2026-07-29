<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Console;

use App\Addons\SeoContentAi\Services\SiteSync\Orchestration\SiteSyncFeatureFlags;
use App\Addons\SeoContentAi\Services\SiteSync\Reconciliation\SiteSyncReconciliationService;
use App\Models\Site;
use App\Support\RuntimeLogger;
use Illuminate\Console\Command;
use Throwable;

/**
 * Lightweight / standard Site Sync V2 reconciliation (delta-first).
 */
final class ReconcileSiteSyncCommand extends Command
{
    protected $signature = 'seo:site-sync-reconcile
        {site_id? : Core sites.id; omit to scan all sites with WP bridge}
        {--mode=quick : quick|standard|full_rebuild}
        {--limit=50 : Max sites when scanning}';

    protected $description = 'Reconcile Site Sync V2 via lightweight WP manifest (delta-first)';

    public function handle(
        SiteSyncReconciliationService $reconciliation,
        SiteSyncFeatureFlags $flags,
    ): int {
        if (! $flags->reconciliationEnabled()) {
            $this->warn('Reconciliation disabled by flag.');

            return self::SUCCESS;
        }

        app(\App\Addons\SeoContentAi\Services\SiteSync\Observability\SiteSyncHeartbeatService::class)
            ->touch('scheduler', ['command' => 'seo:site-sync-reconcile']);

        $mode = (string) $this->option('mode');
        if (! in_array($mode, [
            SiteSyncReconciliationService::MODE_QUICK,
            SiteSyncReconciliationService::MODE_STANDARD,
            SiteSyncReconciliationService::MODE_FULL_REBUILD,
        ], true)) {
            $this->error('Invalid mode: '.$mode);

            return self::FAILURE;
        }

        $siteId = $this->argument('site_id');
        $sites = $siteId !== null
            ? Site::query()->whereKey((int) $siteId)->get()
            : Site::query()
                ->whereNotNull('settings->wordpress_url')
                ->orderBy('id')
                ->limit((int) $this->option('limit'))
                ->get();

        if ($sites->isEmpty()) {
            $this->warn('No sites to reconcile.');

            return self::SUCCESS;
        }

        $ok = 0;
        $fail = 0;
        foreach ($sites as $site) {
            try {
                if (! app(\App\Addons\SeoContentAi\Services\SiteSync\Cutover\SiteSyncCutoverStateService::class)->isV2Writer($site)) {
                    $this->line(sprintf('site=%d skipped mode=%s', (int) $site->id, app(\App\Addons\SeoContentAi\Services\SiteSync\Cutover\SiteSyncCutoverStateService::class)->modeFor($site)));
                    continue;
                }
                $result = $reconciliation->reconcile($site, $mode);
                $line = sprintf(
                    'site=%d success=%s msg=%s',
                    (int) $site->id,
                    ($result['success'] ?? false) ? '1' : '0',
                    (string) ($result['message'] ?? ''),
                );
                if ($result['success'] ?? false) {
                    $this->info($line);
                    $ok++;
                } else {
                    $this->warn($line);
                    $fail++;
                }
            } catch (Throwable $e) {
                $fail++;
                $this->error(sprintf('site=%d error=%s', (int) $site->id, $e->getMessage()));
                RuntimeLogger::report($e, [
                    'command' => 'seo:site-sync-reconcile',
                    'site_id' => (int) $site->id,
                ]);
            }
        }

        $this->line(sprintf('reconciled_ok=%d failed=%d', $ok, $fail));

        return $fail > 0 && $ok === 0 ? self::FAILURE : self::SUCCESS;
    }
}
