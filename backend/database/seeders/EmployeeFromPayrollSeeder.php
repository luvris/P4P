<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\EmployeeStatus;
use App\Models\EmployeeType;
use App\Models\Prefix;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class EmployeeFromPayrollSeeder extends Seeder
{
    public function run(): void
    {
        // ============================================
        // 1. เติม employee_types จาก payrolls (ถ้ามีตัวใหม่)
        // ============================================
        $distinctTypes = DB::table('payrolls')
            ->select('employee_type')
            ->whereNotNull('employee_type')
            ->where('employee_type', '!=', '')
            ->distinct()
            ->pluck('employee_type');

        foreach ($distinctTypes as $i => $type) {
            EmployeeType::updateOrCreate(
                ['name' => $type],
                ['sort_order' => 100 + $i]
            );
        }

        $this->command->info("พบ {$distinctTypes->count()} ประเภทบุคลากรใน payrolls");

        // ============================================
        // 2. ดึงพนักงาน unique จาก payrolls
        // ============================================
        $payrollEmployees = DB::table('payrolls')
            ->select(
                'citizen_id',
                'first_name',
                'last_name',
                'employee_type',
                'bank_account',
                'salary'
            )
            ->whereNotNull('citizen_id')
            ->where('citizen_id', '!=', '')
            ->orderByDesc('id')
            ->get()
            ->unique('citizen_id');

        $this->command->info("พบ {$payrollEmployees->count()} คน (unique citizen_id)");

        // ============================================
        // 3. เตรียม lookup map
        // ============================================
        $defaultStatus = EmployeeStatus::where('name', 'ปฏิบัติงานอยู่')->first();
        $prefixMap     = Prefix::pluck('id', 'name')->toArray();
        $typeMap       = EmployeeType::pluck('id', 'name')->toArray();

        // ============================================
        // 4. Loop สร้าง employees
        // ============================================
        $inserted = 0;
        $skipped  = 0;

        foreach ($payrollEmployees as $row) {
            // ข้ามถ้ามีอยู่แล้ว
            if (Employee::where('citizen_id', $row->citizen_id)->exists()) {
                $skipped++;
                continue;
            }

            // แยกคำนำหน้าออกจาก first_name
            [$prefixId, $firstName] = $this->extractPrefix(
                $row->first_name,
                $prefixMap
            );

            Employee::create([
                'citizen_id'       => $row->citizen_id,
                'prefix_id'        => $prefixId,
                'first_name'       => $firstName,
                'last_name'        => $row->last_name,
                'bank_account'     => $row->bank_account,
                'salary'           => $row->salary,
                'employee_type_id' => $typeMap[$row->employee_type] ?? null,
                'status_id'        => $defaultStatus?->id,
            ]);

            $inserted++;
        }

        // ============================================
        // 5. Summary
        // ============================================
        $this->command->info("เพิ่ม employees: {$inserted} คน");
        $this->command->info("ข้าม (มีอยู่แล้ว): {$skipped} คน");
    }

    /**
     * แยก "นายสมชาย" → [prefix_id, "สมชาย"]
     */
    protected function extractPrefix(?string $fullName, array $prefixMap): array
    {
        $fullName = trim((string) $fullName);
        if ($fullName === '') {
            return [null, ''];
        }

        // เรียงจากยาวไปสั้น กัน "นางสาว" ถูก match เป็น "นาง"
        $prefixes = ['นางสาว', 'นาย', 'นาง'];

        foreach ($prefixes as $prefix) {
            if (str_starts_with($fullName, $prefix)) {
                $rest = trim(mb_substr($fullName, mb_strlen($prefix)));
                return [$prefixMap[$prefix] ?? null, $rest];
            }
        }

        // ไม่มีคำนำหน้าจะคืนชื่อเต็ม
        return [null, $fullName];
    }
}
