<?php

require __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$db = app('db');

$rows = $db->table('payrolls')->orderBy('id')->take(5)->get();
echo "=== PAYROLL RECORDS ===\n";
foreach ($rows as $r) {
    echo json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
}

echo "\n=== OLDEST FILE (import 1) HEADERS + 3 ROWS ===\n";
$file = 'C:/Users/gkwon/P4P/backend/storage/app/private/imports/VhIZV6jc0wHe80o3R5yyC6guW6wF8kK6N1ldZyyE.xlsx';
$ws = IOFactory::load($file)->getActiveSheet();
$rows = $ws->toArray(null, true, true, false);

foreach (array_slice($rows, 0, 4) as $i => $r) {
    echo "ROW {$i}:";
    foreach ($r as $j => $c) {
        echo " [{$j}]=" . var_export($c, true);
    }
    echo "\n";
}