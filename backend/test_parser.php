<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$parser = new App\Services\Parsers\HrXlsxParser();
$result = $parser->parse(storage_path('app/private/imports/x6U3ayypunLYCzmuwfGD52AbMmCSSra3dpte9rns.xlsx'));

echo "Employees: " . count($result['employees']) . PHP_EOL;
echo "Employments: " . count($result['employments']) . PHP_EOL;
