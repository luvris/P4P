<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "Import History (Latest 5):\n";
echo "==========================\n\n";

$imports = App\Models\ImportHistory::orderBy('created_at', 'desc')->take(5)->get();

foreach ($imports as $import) {
    echo "File: " . $import->file_name . "\n";
    echo "Status: " . $import->status . "\n";
    echo "Total: " . $import->total_rows . "\n";
    echo "Success: " . $import->success_rows . "\n";
    echo "Failed: " . $import->failed_rows . "\n";
    echo "Created: " . $import->created_at . "\n";
    echo "\n";
}

echo "\nDatabase Counts:\n";
echo "================\n";
echo "Total Employees: " . App\Models\Employee::count() . "\n";
echo "Total Employment Histories: " . App\Models\EmploymentHistory::count() . "\n";
