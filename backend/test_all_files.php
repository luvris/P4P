<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$files = glob(storage_path('app/private/imports/*.xlsx'));
usort($files, function($a, $b) {
    return filemtime($b) - filemtime($a);
});

$parser = new App\Services\Parsers\HrXlsxParser();

echo "Testing latest 5 files:\n";
echo "======================\n\n";

foreach (array_slice($files, 0, 5) as $file) {
    echo "File: " . basename($file) . "\n";
    echo "Size: " . number_format(filesize($file)) . " bytes\n";
    echo "Modified: " . date('Y-m-d H:i:s', filemtime($file)) . "\n";
    
    try {
        $result = $parser->parse($file);
        echo "Employees: " . count($result['employees']) . "\n";
        echo "Employments: " . count($result['employments']) . "\n";
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
    }
    
    echo "\n";
}
