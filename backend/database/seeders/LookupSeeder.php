<?php

namespace Database\Seeders;

use App\Models\EmployeeStatus;
use App\Models\EmployeeType;
use App\Models\Prefix;
use Illuminate\Database\Seeder;

class LookupSeeder extends Seeder
{
    public function run(): void
    {
        // ========== 1. คำนำหน้า ==========
        $prefixes = [
            ['name' => 'นาย',    'short_name' => 'นาย',  'sort_order' => 1],
            ['name' => 'นาง',    'short_name' => 'นาง',  'sort_order' => 2],
            ['name' => 'นางสาว', 'short_name' => 'น.ส.', 'sort_order' => 3],
        ];

        foreach ($prefixes as $p) {
            Prefix::updateOrCreate(
                ['name' => $p['name']],
                $p
            );
        }

        // ========== 2. ประเภทบุคลากร ==========
        $employeeTypes = [
            'ข้าราชการ',
            'พนักงานราชการ',
            'พนักงานกระทรวง',
            'ลูกจ้าง',
        ];

        foreach ($employeeTypes as $i => $name) {
            EmployeeType::updateOrCreate(
                ['name' => $name],
                ['sort_order' => $i + 1]
            );
        }

        // ========== 3. สถานะ ==========
        $statuses = [
            ['name' => 'ปฏิบัติงานอยู่', 'color' => 'green',  'sort_order' => 1],
            ['name' => 'ลาศึกษาต่อ',    'color' => 'yellow', 'sort_order' => 2],
            ['name' => 'ลาออก',         'color' => 'red',    'sort_order' => 3],
            ['name' => 'ลาเลี้ยงลูก',    'color' => 'blue',   'sort_order' => 4],
        ];

        foreach ($statuses as $s) {
            EmployeeStatus::updateOrCreate(
                ['name' => $s['name']],
                $s
            );
        }

        $this->command->info('LookupSeeder: prefixes, employee_types, employee_statuses seeded.');
    }
}
