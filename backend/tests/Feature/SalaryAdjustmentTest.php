<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeStatus;
use App\Models\Import;
use App\Models\Payroll;
use App\Models\SalaryAdjustment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * ปรับฐานเงินเดือน (POST /api/hr/salary-adjustments)
 *
 * Contract ที่ test คุม:
 * - ฐาน = employees.salary ?? payrolls.total_income ของงวดล่าสุด (ค่า 0 เป็นค่าจริง ห้ามข้าม)
 * - expected_old_salary ใช้ตรวจฐานที่ HR เห็นเท่านั้น (old_salary จาก client ถูกละเลย)
 * - สร้างประวัติ + อัปเดต salary สำเร็จพร้อมกัน มิฉะนั้น rollback ทั้งคู่
 * - ฐานเปลี่ยนระหว่างทำรายการ → 409 SALARY_BASE_CHANGED ไม่มีการบันทึก
 * - ไม่แตะ employees.latest_salary และไม่แตะ payrolls เด็ดขาด
 */
class SalaryAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    private User $hr;

    private EmployeeStatus $workingStatus;

    private int $employeeSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hr = User::create([
            'name'     => 'HR Staff',
            'username' => 'hr_salary_adjust',
            'email'    => 'hr_salary_adjust@example.test',
            'password' => bcrypt('secret-password'),
            'role'     => 'hr',
        ]);

        $this->workingStatus = EmployeeStatus::create(['name' => 'ปฏิบัติงานอยู่', 'sort_order' => 1]);
    }

    /**
     * สร้างพนักงานทดสอบ — citizen_id ไม่ซ้ำกันทุกครั้ง
     *
     * $latestSalary คือค่าคอลัมน์ "เงินเดือน" ในไฟล์ และถูกใช้เป็นยอดรวมรายรับ
     * (total_income) ของงวดล่าสุดใน payrolls ด้วย — คือค่าที่หน้าจอใช้เป็น "เงินเดือนปัจจุบัน"
     */
    private function makeEmployee(?float $salary, ?float $latestSalary, array $overrides = []): Employee
    {
        $this->employeeSeq++;

        $employee = Employee::create(array_merge([
            'citizen_id'    => str_pad((string) (9000000000000 + $this->employeeSeq), 13, '0', STR_PAD_LEFT),
            'employee_id'   => 'P' . str_pad((string) $this->employeeSeq, 4, '0', STR_PAD_LEFT),
            'first_name'    => 'สมชาย',
            'last_name'     => 'ใจดี',
            'status_id'     => $this->workingStatus->id,
            'salary'        => $salary,
            'latest_salary' => $latestSalary,
        ], $overrides));

        if ($latestSalary !== null) {
            $this->makePayroll($employee, $latestSalary);
        }

        return $employee;
    }

    /** สร้างแถว payrolls หนึ่งงวด ให้มียอดรวมรายรับ (total_income) ที่กำหนด */
    private function makePayroll(Employee $employee, float $totalIncome): Payroll
    {
        $import = Import::create([
            'file_name'    => "payroll-{$employee->id}.xlsx",
            'file_path'    => 'imports/payroll.xlsx',
            'file_type'    => 'xlsx',
            'uploaded_by'  => $this->hr->id,
            'status'       => 'completed',
            'import_type'  => 'payroll',
            'fiscal_year'  => 2569,
            'period_month' => 9,
            'period_year'  => 2569,
        ]);

        return Payroll::create([
            'import_id'    => $import->id,
            'citizen_id'   => $employee->citizen_id,
            'first_name'   => $employee->first_name,
            'last_name'    => $employee->last_name,
            'fiscal_year'  => 2569,
            'period_month' => 9,
            'period_year'  => 2569,
            'total_income' => $totalIncome,
            'salary'       => $totalIncome,
        ]);
    }

    private function adjust(array $overrides = [])
    {
        $payload = array_merge([
            'adjustment_date' => '2026-10-07',
            'adjustment_type' => 'ครบ 6 เดือน',
            'note'            => 'ปรับตามเกณฑ์',
        ], $overrides);

        return $this->actingAs($this->hr)->postJson('/api/hr/salary-adjustments', $payload);
    }

    // ============================================================
    // Contract หลัก
    // ============================================================

    public function test_first_adjustment_uses_payroll_total_income_as_base_when_salary_is_null(): void
    {
        $employee = $this->makeEmployee(null, 15000);

        $response = $this->adjust([
            'employee_id'         => $employee->id,
            'expected_old_salary' => 15000,
            'new_salary'          => 16000,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('message', 'บันทึกการปรับฐานเงินเดือนเรียบร้อย');

        $log = SalaryAdjustment::first();
        $this->assertNotNull($log);
        $this->assertSame($employee->id, $log->employee_id);
        $this->assertSame(15000.0, (float) $log->old_salary);
        $this->assertSame(16000.0, (float) $log->new_salary);
        $this->assertSame(1000.0, (float) $log->increase_amount);
        $this->assertSame(6.67, (float) $log->increase_percent);
        $this->assertSame('2026-10-07', $log->adjustment_date->toDateString());
        $this->assertSame('ครบ 6 เดือน', $log->adjustment_type);
        $this->assertSame('ปรับตามเกณฑ์', $log->note);
        $this->assertSame($this->hr->id, $log->created_by);
        $this->assertSame($this->hr->id, $log->updated_by);

        $employee->refresh();
        $this->assertSame(16000.0, (float) $employee->salary);
        $this->assertSame(15000.0, (float) $employee->latest_salary); // ห้ามเปลี่ยน

        // ผลลัพธ์ที่ frontend เอาไปใช้ต้องเป็นค่าที่บันทึกจริง
        $this->assertSame('16000.00', $response->json('data.new_salary'));
        $this->assertSame('15000.00', $response->json('data.old_salary'));
        $this->assertSame('16000.00', $response->json('data.employee.salary'));
    }

    public function test_second_adjustment_uses_updated_salary_not_the_stale_file_value(): void
    {
        $employee = $this->makeEmployee(null, 15000);

        $this->adjust([
            'employee_id'         => $employee->id,
            'expected_old_salary' => 15000,
            'new_salary'          => 16000,
        ])->assertStatus(201);

        $this->adjust([
            'employee_id'         => $employee->id,
            'expected_old_salary' => 16000,
            'new_salary'          => 17000,
        ])->assertStatus(201);

        $logs = SalaryAdjustment::orderBy('id')->get();
        $this->assertCount(2, $logs);
        $this->assertSame(16000.0, (float) $logs[1]->old_salary);
        $this->assertSame(17000.0, (float) $logs[1]->new_salary);
        $this->assertSame(1000.0, (float) $logs[1]->increase_amount);

        $employee->refresh();
        $this->assertSame(17000.0, (float) $employee->salary);
        $this->assertSame(15000.0, (float) $employee->latest_salary); // ยังคงค่าไฟล์เดิม
    }

    public function test_client_old_salary_is_ignored_and_real_db_base_is_logged(): void
    {
        $employee = $this->makeEmployee(null, 15000);

        // old_salary จาก client ผิด แต่ expected ตรงกับฐานจริง → ต้องบันทึกสำเร็จด้วยฐานจริง
        $response = $this->adjust([
            'employee_id'         => $employee->id,
            'old_salary'          => 99999,
            'expected_old_salary' => 15000,
            'new_salary'          => 16000,
        ]);

        $response->assertStatus(201);

        $log = SalaryAdjustment::first();
        $this->assertSame(15000.0, (float) $log->old_salary);
        $this->assertSame(1000.0, (float) $log->increase_amount);
    }

    public function test_adjustment_works_when_payroll_is_missing_but_salary_exists(): void
    {
        $employee = $this->makeEmployee(20000, null);

        $this->adjust([
            'employee_id'         => $employee->id,
            'expected_old_salary' => 20000,
            'new_salary'          => 21000,
        ])->assertStatus(201);

        $log = SalaryAdjustment::first();
        $this->assertSame(20000.0, (float) $log->old_salary);
        $this->assertSame(1000.0, (float) $log->increase_amount);

        $employee->refresh();
        $this->assertSame(21000.0, (float) $employee->salary);
        $this->assertNull($employee->latest_salary);
    }

    public function test_both_salary_and_payroll_null_returns_422_and_writes_nothing(): void
    {
        $employee = $this->makeEmployee(null, null);

        $response = $this->adjust([
            'employee_id'         => $employee->id,
            'expected_old_salary' => 15000,
            'new_salary'          => 16000,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'ไม่มีข้อมูลฐานเงินเดือน');

        $this->assertSame(0, SalaryAdjustment::count());
        $employee->refresh();
        $this->assertNull($employee->salary);
    }

    public function test_zero_salary_is_a_real_base_and_does_not_fall_back_to_payroll_income(): void
    {
        $employee = $this->makeEmployee(0, 15000);

        $this->adjust([
            'employee_id'         => $employee->id,
            'expected_old_salary' => 0,
            'new_salary'          => 1000,
        ])->assertStatus(201);

        $log = SalaryAdjustment::first();
        $this->assertSame(0.0, (float) $log->old_salary);
        $this->assertSame(1000.0, (float) $log->new_salary);
        $this->assertSame(1000.0, (float) $log->increase_amount);
        $this->assertNull($log->increase_percent); // ฐาน 0 → ห้ามหารด้วยศูนย์

        $employee->refresh();
        $this->assertSame(1000.0, (float) $employee->salary);
        $this->assertSame(15000.0, (float) $employee->latest_salary);
    }

    public function test_decrease_is_logged_with_negative_increase_and_percent(): void
    {
        $employee = $this->makeEmployee(15000, 15000);

        $this->adjust([
            'employee_id'         => $employee->id,
            'expected_old_salary' => 15000,
            'new_salary'          => 14000,
        ])->assertStatus(201);

        $log = SalaryAdjustment::first();
        $this->assertSame(-1000.0, (float) $log->increase_amount);
        $this->assertSame(-6.67, (float) $log->increase_percent);

        $employee->refresh();
        $this->assertSame(14000.0, (float) $employee->salary);
        $this->assertSame(15000.0, (float) $employee->latest_salary); // ไม่เปลี่ยน
    }

    public function test_omitting_expected_old_salary_returns_422_without_writing(): void
    {
        $employee = $this->makeEmployee(null, 15000);

        $this->adjust([
            'employee_id' => $employee->id,
            'new_salary'  => 16000,
        ])->assertStatus(422)->assertJsonValidationErrors(['expected_old_salary']);

        $this->assertSame(0, SalaryAdjustment::count());
        $employee->refresh();
        $this->assertNull($employee->salary);
    }

    public function test_null_expected_old_salary_returns_422_without_writing(): void
    {
        $employee = $this->makeEmployee(null, 15000);

        $this->adjust([
            'employee_id'         => $employee->id,
            'expected_old_salary' => null,
            'new_salary'          => 16000,
        ])->assertStatus(422)->assertJsonValidationErrors(['expected_old_salary']);

        $this->assertSame(0, SalaryAdjustment::count());
        $employee->refresh();
        $this->assertNull($employee->salary);
    }

    // ============================================================
    // Transaction — ล้มเหลวต้องไม่มีข้อมูลค้างฝั่งใดฝั่งหนึ่ง
    // ============================================================

    public function test_exception_after_creating_log_rolls_back_log_and_keeps_existing_salary(): void
    {
        $employee = $this->makeEmployee(15000, 15000);

        // บังคับให้เกิด error หลังinsert log แล้ว แต่ก่อนอัปเดต salary
        SalaryAdjustment::created(function (): void {
            throw new RuntimeException('forced failure after log insert');
        });

        try {
            $this->adjust([
                'employee_id'         => $employee->id,
                'expected_old_salary' => 15000,
                'new_salary'          => 16000,
            ])->assertStatus(500);
        } finally {
            SalaryAdjustment::flushEventListeners();
        }

        $this->assertSame(0, SalaryAdjustment::withTrashed()->count());
        $employee->refresh();
        $this->assertSame(15000.0, (float) $employee->salary);
    }

    public function test_exception_after_creating_log_keeps_salary_null_when_it_was_null(): void
    {
        $employee = $this->makeEmployee(null, 15000);

        SalaryAdjustment::created(function (): void {
            throw new RuntimeException('forced failure after log insert');
        });

        try {
            $this->adjust([
                'employee_id'         => $employee->id,
                'expected_old_salary' => 15000,
                'new_salary'          => 16000,
            ])->assertStatus(500);
        } finally {
            SalaryAdjustment::flushEventListeners();
        }

        $this->assertSame(0, SalaryAdjustment::withTrashed()->count());
        $employee->refresh();
        $this->assertNull($employee->salary);
    }

    // ============================================================
    // ฐานเปลี่ยนระหว่างทำรายการ → 409 SALARY_BASE_CHANGED
    // ============================================================

    public function test_changed_base_returns_409_with_latest_base_and_changes_nothing(): void
    {
        // ฟอร์มคาดฐาน 15,000 แต่ DB เป็น 16,000
        $employee = $this->makeEmployee(16000, 15000);

        $response = $this->adjust([
            'employee_id'         => $employee->id,
            'expected_old_salary' => 15000,
            'new_salary'          => 17000,
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('code', 'SALARY_BASE_CHANGED')
            ->assertJsonPath('message', 'เงินเดือนของพนักงานถูกเปลี่ยนระหว่างทำรายการ กรุณาตรวจสอบยอดใหม่และยืนยันอีกครั้ง')
            ->assertJsonPath('data.base_source', 'salary');

        $this->assertSame(16000.0, (float) $response->json('data.base'));
        $this->assertSame(16000.0, (float) $response->json('data.salary'));
        $this->assertSame(15000.0, (float) $response->json('data.latest_payroll_income'));

        $this->assertSame(0, SalaryAdjustment::count());
        $employee->refresh();
        $this->assertSame(16000.0, (float) $employee->salary); // คงยอดเดิม ไม่ใช่ 17,000
    }

    public function test_confirming_again_with_the_new_base_succeeds(): void
    {
        $employee = $this->makeEmployee(16000, 15000);

        // ยืนยันรอบแรกผิดฐาน → 409
        $this->adjust([
            'employee_id'         => $employee->id,
            'expected_old_salary' => 15000,
            'new_salary'          => 17000,
        ])->assertStatus(409);

        // ยืนยันใหม่ด้วยฐานล่าสุด → สำเร็จ
        $this->adjust([
            'employee_id'         => $employee->id,
            'expected_old_salary' => 16000,
            'new_salary'          => 17000,
        ])->assertStatus(201);

        $log = SalaryAdjustment::first();
        $this->assertSame(16000.0, (float) $log->old_salary);
        $this->assertSame(17000.0, (float) $log->new_salary);
        $this->assertSame(1000.0, (float) $log->increase_amount);

        $employee->refresh();
        $this->assertSame(17000.0, (float) $employee->salary);
        $this->assertSame(15000.0, (float) $employee->latest_salary);
    }

    public function test_conflict_reports_payroll_income_as_base_source_when_salary_is_null(): void
    {
        $employee = $this->makeEmployee(null, 15000);

        // นำเข้าไฟล์งวดใหม่ทับยอดรวมรายรับ → ฐานที่ HR เห็น (15,000) ไม่ตรงแล้ว
        Payroll::where('citizen_id', $employee->citizen_id)->update(['total_income' => 16000]);

        $response = $this->adjust([
            'employee_id'         => $employee->id,
            'expected_old_salary' => 15000,
            'new_salary'          => 17000,
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('code', 'SALARY_BASE_CHANGED')
            ->assertJsonPath('data.base_source', 'payroll_total_income');

        $this->assertSame(16000.0, (float) $response->json('data.base'));
        $this->assertSame(16000.0, (float) $response->json('data.latest_payroll_income'));
        $this->assertNull($response->json('data.salary'));

        $this->assertSame(0, SalaryAdjustment::count());
        $employee->refresh();
        $this->assertNull($employee->salary);
    }

    public function test_expected_15000_matches_base_15000_00_exactly(): void
    {
        $employee = $this->makeEmployee(15000, 15000);

        $response = $this->adjust([
            'employee_id'         => $employee->id,
            'expected_old_salary' => 15000,      // int จากฟอร์ม
            'new_salary'          => 16000,
        ]);

        $response->assertStatus(201); // ห้ามถือว่าฐานเปลี่ยน
        $this->assertSame(15000.0, (float) SalaryAdjustment::first()->old_salary);
    }

    // ============================================================
    // ความจุตาม schema
    // ============================================================

    public function test_calculated_percent_beyond_schema_capacity_is_rejected(): void
    {
        $employee = $this->makeEmployee(1000, 1000);

        // percent = (9,999,999,999.99 - 1,000) / 1,000 * 100 เกิน decimal(6,2)
        $this->adjust([
            'employee_id'         => $employee->id,
            'expected_old_salary' => 1000,
            'new_salary'          => 9999999999.99,
        ])->assertStatus(422);

        $this->assertSame(0, SalaryAdjustment::count());
        $employee->refresh();
        $this->assertSame(1000.0, (float) $employee->salary);
    }

    public function test_new_salary_beyond_money_column_capacity_is_rejected(): void
    {
        $employee = $this->makeEmployee(15000, 15000);

        $this->adjust([
            'employee_id'         => $employee->id,
            'expected_old_salary' => 15000,
            'new_salary'          => 10000000000, // > decimal(12,2)
        ])->assertStatus(422);

        $this->assertSame(0, SalaryAdjustment::count());
        $employee->refresh();
        $this->assertSame(15000.0, (float) $employee->salary);
    }

    // ============================================================
    // ข้อมูลแสดงผล: suggest + payload หน้าพนักงาน
    // ============================================================

    public function test_suggest_returns_salary_and_payroll_income_and_new_salary_after_adjustment(): void
    {
        $employee = $this->makeEmployee(null, 15000);

        $this->actingAs($this->hr)
            ->getJson('/api/hr/employees/suggest?q=' . $employee->citizen_id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.latest_payroll_income', 15000)
            ->assertJsonPath('data.0.income_period_label', 'กันยายน 2569');

        $this->assertNull($employee->salary); // salary ยัง null → UI ใช้ latest_payroll_income เป็นเงินเดือนปัจจุบัน

        $this->adjust([
            'employee_id'         => $employee->id,
            'expected_old_salary' => 15000,
            'new_salary'          => 16000,
        ])->assertStatus(201);

        // หลังปรับ suggest ต้องคืน salary ใหม่ (และยังคืนยอดจากไฟล์ไว้เทียบ)
        $this->actingAs($this->hr)
            ->getJson('/api/hr/employees/suggest?q=' . $employee->citizen_id)
            ->assertOk()
            ->assertJsonPath('data.0.salary', '16000.00')
            ->assertJsonPath('data.0.latest_payroll_income', 15000);

        // payload หน้ารายชื่อคืนทั้งค่า ให้แสดง salary ?? latest_payroll_income ได้
        $this->actingAs($this->hr)
            ->getJson('/api/hr/employees?search=' . $employee->citizen_id)
            ->assertOk()
            ->assertJsonPath('data.0.salary', '16000.00')
            ->assertJsonPath('data.0.latest_payroll.total_income', '15000.00');
    }

    public function test_missing_employee_inside_transaction_answers_not_found_without_writing(): void
    {
        $employee = $this->makeEmployee(null, 15000);

        // soft-deleted ยังผ่าน validation exists:employees,id (แถวยังอยู่ในตาราง)
        // แต่ model query ใน transaction หาไม่เจอ → ตอบ 404 แบบ not-found ของระบบ ไม่บันทึกอะไร
        $employee->delete();

        $this->adjust([
            'employee_id'         => $employee->id,
            'expected_old_salary' => 15000,
            'new_salary'          => 16000,
        ])->assertNotFound();

        $this->assertSame(0, SalaryAdjustment::withTrashed()->count());
        $this->assertNull($employee->fresh()->salary);
    }

    public function test_guests_cannot_create_salary_adjustments(): void
    {
        $employee = $this->makeEmployee(null, 15000);

        $this->postJson('/api/hr/salary-adjustments', [
            'employee_id'         => $employee->id,
            'expected_old_salary' => 15000,
            'new_salary'          => 16000,
            'adjustment_date'     => '2026-10-07',
        ])->assertUnauthorized();

        $this->assertSame(0, SalaryAdjustment::count());
    }
}
