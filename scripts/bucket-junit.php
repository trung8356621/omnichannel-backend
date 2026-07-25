<?php

declare(strict_types=1);

$xmlPath = $argv[1] ?? 'storage/app/testing-audit/seo-unit.xml';
$x = simplexml_load_file($xmlPath);
if ($x === false) {
    fwrite(STDERR, "bad xml\n");
    exit(1);
}

$b = [
    'ENV_MYSQL' => [],
    'CONFIG' => [],
    'FINAL' => [],
    'FAIL' => [],
    'SKIP' => [],
    'OTHER' => [],
];

foreach ($x->xpath('//testcase') ?: [] as $tc) {
    $file = basename(str_replace('\\', '/', (string) $tc['file']));
    $status = 'pass';
    $msg = '';
    if (isset($tc->failure)) {
        $status = 'failure';
        $msg = (string) $tc->failure;
    } elseif (isset($tc->error)) {
        $status = 'error';
        $msg = (string) $tc->error;
    } elseif (isset($tc->skipped)) {
        $status = 'skipped';
        $msg = (string) $tc->skipped;
    }
    if ($status === 'pass') {
        continue;
    }

    if ($status === 'skipped') {
        $b['SKIP'][$file] = ($b['SKIP'][$file] ?? 0) + 1;
    } elseif (str_contains($msg, '2002') || str_contains($msg, 'actively refused') || str_contains($msg, 'Connection refused')) {
        $b['ENV_MYSQL'][$file] = ($b['ENV_MYSQL'][$file] ?? 0) + 1;
    } elseif (str_contains($msg, 'Target class [config]')) {
        $b['CONFIG'][$file] = ($b['CONFIG'][$file] ?? 0) + 1;
    } elseif (str_contains($msg, 'final') || str_contains($msg, 'ClassIsFinal')) {
        $b['FINAL'][$file] = ($b['FINAL'][$file] ?? 0) + 1;
    } elseif ($status === 'failure') {
        $b['FAIL'][$file] = ($b['FAIL'][$file] ?? 0) + 1;
    } else {
        $b['OTHER'][$file] = ($b['OTHER'][$file] ?? 0) + 1;
    }
}

foreach ($b as $k => $rows) {
    echo $k.'='.count($rows).PHP_EOL;
    foreach ($rows as $f => $n) {
        echo "  {$f} {$n}".PHP_EOL;
    }
}
