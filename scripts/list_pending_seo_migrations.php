<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$mysql = config('database.connections.mysql');
$mysql['database'] = 'omi_seo_ai';
config(['database.connections.omi_seo_ai' => $mysql]);
Illuminate\Support\Facades\DB::purge('omi_seo_ai');

$ran = Illuminate\Support\Facades\DB::connection('omi_seo_ai')->table('migrations')->pluck('migration')->all();
$ranMap = array_fill_keys($ran, true);

$pending = [];
foreach (glob($root.'/addons/*/database/migrations/*.php') ?: [] as $file) {
    $base = basename($file, '.php');
    if (! isset($ranMap[$base])) {
        $pending[] = $base;
    }
}
sort($pending);
echo 'pending='.count($pending).PHP_EOL;
foreach (array_slice($pending, 0, 30) as $p) {
    echo $p.PHP_EOL;
}
