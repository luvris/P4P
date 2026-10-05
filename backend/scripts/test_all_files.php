<?php

/**
 * ทดลองอ่านไฟล์เงินเดือนที่อยู่ใน storage ล่าสุด 5 ไฟล์
 * ใช้ตอน debug ปัญหาการอ่านไฟล์โดยไม่ต้องผ่านหน้าเว็บ
 *
 * รัน: php scripts/test_all_files.php
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$files = glob(storage_path('app/private/imports/*.xlsx'));
usort($files, function ($a, $b) {
    return filemtime($b) - filemtime($a);
});

$parser = new App\Services\Parsers\NewFormatPayrollParser();

echo "Testing latest 5 files:\n";
echo "======================\n\n";

if ($files === []) {
    echo "ไม่พบไฟล์ที่อัปโหลดไว้ใน storage/app/private/imports\n";

    return;
}

foreach (array_slice($files, 0, 5) as $file) {
    echo "File: " . basename($file) . "\n";
    echo "Size: " . number_format(filesize($file)) . " bytes\n";
    echo "Modified: " . date('Y-m-d H:i:s', filemtime($file)) . "\n";
    echo "New format: " . (App\Services\Parsers\NewFormatPayrollParser::looksLikeNewFormat($file) ? 'yes' : 'no') . "\n";

    try {
        $rows = $parser->parse($file);
        echo "Rows: " . count($rows) . "\n";

        // สรุปตามงวด เพื่อดูว่าไฟล์รวมกี่งวด
        $byPeriod = [];
        foreach ($rows as $row) {
            $key = ($row['fiscal_year'] ?? '?') . '/' . ($row['period_month'] ?? '?');
            $byPeriod[$key] = ($byPeriod[$key] ?? 0) + 1;
        }
        ksort($byPeriod);
        echo "Periods: " . json_encode($byPeriod, JSON_UNESCAPED_UNICODE) . "\n";
    } catch (Throwable $e) {
        echo "Error: " . $e->getMessage() . "\n";
    }

    echo "\n";
}