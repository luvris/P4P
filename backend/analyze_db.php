<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "Database Analysis:\n";
echo "==================\n\n";

$db = DB::connection();

// Check tables
echo "Employment Histories:\n";
echo "Total rows: " . DB::table('employment_histories')->count() . "\n";
echo "Distinct employee IDs: " . DB::table('employment_histories')->distinct('employee_id')->count('employee_id') . "\n";
echo "Latest created_at: " . DB::table('employment_histories')->max('created_at') . "\n";
echo "\n";

echo "Employees:\n";
echo "Total rows: " . DB::table('employees')->count() . "\n";
echo "Latest created_at: " . DB::table('employees')->max('created_at') . "\n";
echo "\n";

// Check for any limits in recent inserts
echo "Recent Employment History sample (last 5):\n";
$recent = DB::table('employment_histories')
    ->orderBy('id', 'desc')
    ->limit(5)
    ->get(['id', 'employee_id', 'created_at']);

foreach ($recent as $r) {
    echo "ID: {$r->id}, Employee: {$r->employee_id}, Created: {$r->created_at}\n";
}
