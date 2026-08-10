<?php

declare(strict_types=1);

$root = dirname(__DIR__);

$batch = [
    // Remaining Http leftovers
    ['app/Addons/SeoContentAi/Http/Controllers/GoogleSearchConsoleOAuthController.php', 'addons/search-intelligence/src/Http/Controllers/GoogleSearchConsoleOAuthController.php'],
    ['app/Addons/SeoContentAi/Http/Controllers/TeamMessageController.php', 'addons/seo/src/Http/Controllers/TeamMessageController.php'],
    ['app/Addons/SeoContentAi/Http/Controllers/SeoPanelLogoutController.php', 'addons/seo/src/Http/Controllers/SeoPanelLogoutController.php'],
    ['app/Addons/SeoContentAi/Http/Controllers/SeoPanelRedirectController.php', 'addons/seo/src/Http/Controllers/SeoPanelRedirectController.php'],
    ['app/Addons/SeoContentAi/Http/Middleware/CheckMainRole.php', 'addons/seo/src/Http/Middleware/CheckMainRole.php'],
    ['app/Addons/SeoContentAi/Http/Middleware/SeoAuthenticate.php', 'addons/seo/src/Http/Middleware/SeoAuthenticate.php'],
    ['app/Addons/SeoContentAi/Http/Middleware/SeoPlannerPermissionMiddleware.php', 'addons/seo/src/Http/Middleware/SeoPlannerPermissionMiddleware.php'],
    ['app/Addons/SeoContentAi/Http/Middleware/SetDynamicSeoDatabase.php', 'addons/search-foundation/src/Http/Middleware/SetDynamicSeoDatabase.php'],
    ['app/Addons/SeoContentAi/Http/Requests/TestOptimizeLocalWebpRequest.php', 'addons/media/src/Http/Requests/TestOptimizeLocalWebpRequest.php'],

    // Panel shell Filament concerns/pages → seo (panel host lives with seo entitlement for now)
    ['app/Addons/SeoContentAi/Filament/Concerns/BelongsToAdminAutomationPanel.php', 'addons/seo/src/Filament/Concerns/BelongsToAdminAutomationPanel.php'],
    ['app/Addons/SeoContentAi/Filament/Concerns/HidesFilamentPageHeader.php', 'addons/seo/src/Filament/Concerns/HidesFilamentPageHeader.php'],
    ['app/Addons/SeoContentAi/Filament/Concerns/InteractsWithSeoConnectionResourceRoutes.php', 'addons/seo/src/Filament/Concerns/InteractsWithSeoConnectionResourceRoutes.php'],
    ['app/Addons/SeoContentAi/Filament/Concerns/InteractsWithSeoConnectionRoutes.php', 'addons/seo/src/Filament/Concerns/InteractsWithSeoConnectionRoutes.php'],
    ['app/Addons/SeoContentAi/Filament/Concerns/InteractsWithSeoFilamentFormSaveActions.php', 'addons/seo/src/Filament/Concerns/InteractsWithSeoFilamentFormSaveActions.php'],
    ['app/Addons/SeoContentAi/Filament/Concerns/RedirectsSeoAutomationToAdmin.php', 'addons/seo/src/Filament/Concerns/RedirectsSeoAutomationToAdmin.php'],
    ['app/Addons/SeoContentAi/Filament/Pages/SeoPanelPage.php', 'addons/seo/src/Filament/Pages/SeoPanelPage.php'],
    ['app/Addons/SeoContentAi/Filament/Pages/Auth/SeoChangePassword.php', 'addons/seo/src/Filament/Pages/Auth/SeoChangePassword.php'],
    ['app/Addons/SeoContentAi/Filament/Pages/Auth/SeoEditProfile.php', 'addons/seo/src/Filament/Pages/Auth/SeoEditProfile.php'],
    ['app/Addons/SeoContentAi/Filament/Pages/Auth/SeoLogin.php', 'addons/seo/src/Filament/Pages/Auth/SeoLogin.php'],
    ['app/Addons/SeoContentAi/Filament/Resources/SeoPanelResource.php', 'addons/seo/src/Filament/Resources/SeoPanelResource.php'],
];

$rewrites = [];

foreach ($batch as [$fromRel, $toRel]) {
    $from = $root.'/'.$fromRel;
    $to = $root.'/'.$toRel;
    if (! is_file($from)) {
        echo "MISS {$fromRel}\n";
        continue;
    }
    $content = file_get_contents($from);
    if (! preg_match('/namespace\s+([^;]+);/', $content, $m)) {
        echo "NO-NS {$fromRel}\n";
        continue;
    }
    $oldNs = trim($m[1]);
    if (! preg_match('#/addons/([^/]+)/src/(.+)$#', str_replace('\\', '/', $to), $pm)) {
        echo "BAD-TO {$toRel}\n";
        continue;
    }
    $pascal = str_replace(' ', '', ucwords(str_replace('-', ' ', $pm[1])));
    $rel = preg_replace('/\.php$/', '', $pm[2]);
    $dir = dirname($rel);
    $newNs = 'Omnichannel\\Addons\\'.$pascal.($dir === '.' ? '' : '\\'.str_replace('/', '\\', $dir));
    $class = pathinfo($from, PATHINFO_FILENAME);
    $content = str_replace('namespace '.$oldNs.';', 'namespace '.$newNs.';', $content);
    if (! is_dir(dirname($to))) {
        mkdir(dirname($to), 0777, true);
    }
    file_put_contents($to, $content);
    unlink($from);
    $rewrites[$oldNs.'\\'.$class] = $newNs.'\\'.$class;
    $rewrites[$oldNs] = $newNs;
    // Also map common wrong rewrite targets from earlier partial patches
    if (str_contains($oldNs, 'SeoContentAi')) {
        $wrong = str_replace('App\\Addons\\SeoContentAi\\', 'Omnichannel\\Addons\\Seo\\', $oldNs);
        $rewrites[$wrong] = $newNs;
        $rewrites[$wrong.'\\'.$class] = $newNs.'\\'.$class;
    }
    echo "OK {$fromRel} -> {$toRel}\n";
}

uksort($rewrites, static fn ($a, $b) => strlen($b) <=> strlen($a));

$files = [];
foreach (['app', 'addons', 'tests'] as $d) {
    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$d, FilesystemIterator::SKIP_DOTS));
    foreach ($rii as $ff) {
        if ($ff->isFile() && $ff->getExtension() === 'php') {
            $files[] = $ff->getPathname();
        }
    }
}

$patched = 0;
foreach ($files as $file) {
    $c = file_get_contents($file);
    $o = $c;
    foreach ($rewrites as $old => $new) {
        if ($old !== $new) {
            $c = str_replace($old, $new, $c);
        }
    }
    if ($c !== $o) {
        file_put_contents($file, $c);
        $patched++;
    }
}

echo "patched {$patched}\n";
