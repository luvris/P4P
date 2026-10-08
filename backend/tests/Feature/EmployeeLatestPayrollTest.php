<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeStatus;
use App\Models\Import;
use App\Models\Payroll;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * \"เงินเดือนจากไฟล์ล่าสุด\" ของบุคลากร (latest_payroll_income)
 *
 * ปีงบประมาณเริ่มที่เดือน 10 (ต.ค.) ดังนั้นปีงบ 2569 = ต.ค. 2568 → ก.ย. 2569
 * การเรียงด้วย fiscal_year + period_month แบบตรง ๆ จะทำให้ ธ.ค. 2568 (เดือน 12)
 * ถูกมองเป็นงวดล่าสุด แทน พ.ค. 2569 (เดือน 5) — เทสต์นี้ล็อกพฤติกรรมที่ถูกต้องไว้
 */
class EmployeeLatestPayrollTest extends TestCase
{
    use RefreshDatabase;

    private User $finance;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->finance = User::create([
            'name'     => 'Finance',
            'username' => 'finance_latest_payroll',
            'email'    => 'finance_latest_payroll@example.test',
            'password' => bcrypt('secret-password'),
            'role'     => 'finance',
        ]);

        $active = EmployeeStatus::create(['name' => EmployeeStatus::ACTIVE_NAME, 'sort_order' => 1]);

        $this->employee = Employee::create([
            'citizen_id' => '1111111111111',
            'first_name' => 'ทดสอบ',
            'last_name'  => 'งวดล่าสุด',
            'status_id'  => $active->id,
            'salary'     => null, // ฐานเงินเดือนยังว่าง → UI ต้องใช้ยอดจากไฟล์
        ]);
    }

    /** เพิ่มแถว payroll ของงวดหนึ่ง (ปี/เดือน เป็น พ.ศ.) */
    private function makePayroll(int $periodYear, int $periodMonth, float $totalIncome): void
    {
        $fiscalYear = $periodMonth >= 10 ? $periodYear + 1 : $periodYear;

        $import = Import::create([
            'file_name'     => "payroll-{$periodYear}-{$periodMonth}.xlsx",
            'file_path'     => 'imports/payroll.xlsx',
            'file_type'     => 'xlsx',
            'uploaded_by'   => $this->finance->id,
            'status'        => 'completed',
            'import_type'   => 'payroll',
            'fiscal_year'   => $fiscalYear,
            'period_month'  => $periodMonth,
            'period_year'   => $periodYear,
        ]);

        Payroll::create([
            'import_id'     => $import->id,
            'citizen_id'    => $this->employee->citizen_id,
            'first_name'    => 'ทดสอบ',
            'last_name'     => 'งวดล่าสุด',
            'fiscal_year'   => $fiscalYear,
            'period_month'  => $periodMonth,
            'period_year'   => $periodYear,
            'salary'        => 1000,
            'total_income'  => $totalIncome,
            'bank_account'  => "{$periodMonth}-account",
        ]);
    }

    public function test_latest_payroll_income_comes_from_the_most_recent_period_not_the_highest_month(): void
    {
        // งวดแรกของปีงบ 2569 (เดือน 12 = ธ.ค. 2568) มาก่อน
        $this->makePayroll(2568, 12, 50000);
        // แล้วตามด้วยงวดล่าสุด (เดือน 5 = พ.ค. 2569)
        $this->makePayroll(2569, 5, 88000);

        $employee = $this->employee->fresh();

        $this->assertSame(88000.0, $employee->latest_payroll_income);
        $this->assertSame('พฤษภาคม 2569', $employee->income_period_label);
        $this->assertSame('5-account', $employee->bank_account_number);
    }

    public function test_it_still_uses_the_newest_row_within_the_same_period(): void
    {
        $this->makePayroll(2569, 5, 70000);
        $this->makePayroll(2569, 5, 95000); // อัปโหลดซ้ำงวดเดิม → แถวล่าสุดชนะ

        $this->assertSame(95000.0, $this->employee->fresh()->latest_payroll_income);
    }

    public function test_it_returns_null_when_the_employee_has_no_payroll(): void
    {
        $this->assertNull($this->employee->fresh()->latest_payroll_income);
        $this->assertNull($this->employee->fresh()->income_period_label);
    }

    public function test_the_employee_list_exposes_the_latest_file_income(): void
    {
        $this->makePayroll(2568, 12, 50000);
        $this->makePayroll(2569, 5, 88000);

        $this->actingAs($this->finance)
            ->getJson('/api/hr/employees?per_page=5')
            ->assertOk()
            ->assertJsonPath('data.0.latest_payroll_income', 88000)
            ->assertJsonPath('data.0.income_period_label', 'พฤษภาคม 2569');
    }
}
