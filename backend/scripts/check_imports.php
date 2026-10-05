<?php

/**
 * สรุปประวัติการนำเข้าข้อมูลล่าสุด 5 รายการ พร้อมจำนวนข้อมูลในตารางหลัก
 *
 * รัน: php scripts/check_imports.php
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\Employee;
use App\Models\Import;
use App\Models\Payroll;

echo "Import History (Latest 5):\n";
echo "==========================\n\n";

$imports = Import::orderBy('created_at', 'desc')->take(5)->get();

if ($imports->isEmpty()) {
    echo "(ยังไม่มีประวัติการนำเข้าข้อมูล)\n";
}

foreach ($imports as $import) {
    echo "File: " . $import->file_name . "\n";
    echo "Type: " . $import->import_type . "\n";
    echo "Status: " . $import->status . "\n";
    echo "Total: " . $import->total_rows . "\n";
    echo "Success: " . $import->success_rows . "\n";
    echo "Inserted: " . $import->inserted_rows . " / Updated: " . $import->updated_rows . "\n";
    echo "Errors: " . $import->error_rows . "\n";
    echo "Created: " . $import->created_at . "\n";
    echo "\n";
}

echo "\nDatabase Counts:\n";
echo "================\n";
echo "Employees: " . Employee::count() . "\n";
echo "Payrolls: " . Payroll::count() . "\n";
echo "  - รวมรายรับทั้งหมด: " . Payroll::sum('total_income') . "\n";
echo "Employees with position: " . Employee::whereNotNull('position_id')->count() . "\n";