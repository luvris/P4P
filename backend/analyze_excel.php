<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$file = storage_path('app/private/imports/mDHbtLj45JwKyaqN40Tj4MxSCq5WvPF1MQZRNkpG.xlsx');

echo "Analyzing: " . basename($file) . "\n";
echo "Size: " . number_format(filesize($file)) . " bytes\n\n";

$spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($file);
$worksheet = $spreadsheet->getActiveSheet();

echo "Sheet name: " . $worksheet->getTitle() . "\n";
echo "Highest row: " . $worksheet->getHighestRow() . "\n";
echo "Highest column: " . $worksheet->getHighestColumn() . "\n\n";

// Count non-empty rows
$rows = $worksheet->toArray(null, true, true, false);
$header = array_shift($rows);

echo "Header columns: " . count($header) . "\n";
echo "Total data rows (after header): " . count($rows) . "\n\n";

// Count truly non-empty rows
$nonEmptyCount = 0;
$emptyRows = [];

foreach ($rows as $index => $row) {
    $hasData = false;
    foreach ($row as $cell) {
        if (!empty($cell)) {
            $hasData = true;
            break;
        }
    }
    
    if ($hasData) {
        $nonEmptyCount++;
    } else {
        $emptyRows[] = $index + 2; // +2 because 1-indexed and we shifted header
    }
}

echo "Non-empty data rows: $nonEmptyCount\n";
echo "Empty rows: " . count($emptyRows) . "\n";

if (count($emptyRows) > 0 && count($emptyRows) <= 20) {
    echo "Empty row numbers: " . implode(', ', array_slice($emptyRows, 0, 20)) . "\n";
}
