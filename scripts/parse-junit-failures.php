<?php

declare(strict_types=1);

$files = $argv;
array_shift($files);
if ($files === []) {
    $files = [
        'storage/app/testing-audit/unit.xml',
        'storage/app/testing-audit/feature.xml',
        'storage/app/testing-audit/seo-unit.xml',
        'storage/app/testing-audit/seo-feature.xml',
    ];
}

foreach ($files as $f) {
    if (! is_file($f)) {
        echo "MISSING {$f}\n";
        continue;
    }
    $x = @simplexml_load_file($f);
    if ($x === false) {
        echo "BAD_XML {$f}\n";
        continue;
    }
    $by = [];
    $passFiles = [];
    foreach ($x->xpath('//testcase') ?: [] as $tc) {
        $class = (string) $tc['classname'];
        $file = (string) $tc['file'];
        $name = (string) $tc['name'];
        $key = $file !== '' ? str_replace('\\', '/', $file) : $class;
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
            $passFiles[$key] = ($passFiles[$key] ?? 0) + 1;
            continue;
        }
        $by[$key]['status'] = $status;
        $by[$key]['class'] = $class;
        $by[$key]['methods'][] = $name;
        $by[$key]['sample'] = substr(preg_replace('/\s+/', ' ', $msg) ?? $msg, 0, 240);
    }
    echo '=== '.basename($f)." ===\n";
    echo 'fail_files='.count($by).' pass_only_approx='.count(array_diff_key($passFiles, $by))."\n";
    foreach ($by as $k => $v) {
        $base = basename($k);
        $n = count($v['methods']);
        echo "{$v['status']}|{$base}|{$n}|{$v['sample']}\n";
    }
}
