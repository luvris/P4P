<?php

namespace Tests\Feature;

use App\Models\Duty;
use App\Models\Employee;
use App\Models\EmployeeStatus;
use App\Models\Group;
use App\Models\Import;
use App\Models\Payroll;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReserveFundPercentTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Import $import;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'name'     => 'HR Tester',
            'username' => 'hr_reserve',
            'email'    => 'hr_reserve@example.test',
            'password' => bcrypt('secret-password'),
            'role'     => 'hr',
        ]);

        $duty = Duty::create(['name' => 'ด้านการพยาบาล']);
        $group = Group::create(['name' => 'กลุ่มงานการพยาบาลผู้ป่วยนอก', 'duty_id' => $duty->id]);
        Work::create(['name' => 'งานผู้ป่วยนอก', 'group_id' => $group->id]);

        // ฐานเงินสำรองนับเฉพาะคนที่ยังปฏิบัติงานอยู่
        $working = EmployeeStatus::create(['name' => 'ปฏิบัติงานอยู่', 'sort_order' => 1]);

        Employee::create([
            'citizen_id' => '1111111111111',
            'first_name' => 'สมชาย',
            'last_name'  => 'ใจดี',
            'duty_id'    => $duty->id,
            'group_id'   => $group->id,
            'status_id'  => $working->id,
        ]);

        $this->import = Import::create([
            'file_name'   => 'payroll.xlsx',
            'file_path'   => 'imports/payroll.xlsx',
            'file_type'   => 'xlsx',
            'uploaded_by' => $this->user->id,
            'status'      => 'completed',
        ]);

        // ฐานคำนวณ = 20000 + 3000 + 1500 + 5500 = 30000
        // net_income ตั้งค่าต่างออกไป เพื่อยืนยันว่าไม่ถูกใช้เป็นฐาน
        Payroll::create([
            'import_id'          => $this->import->id,
            'citizen_id'         => '1111111111111',
            'first_name'         => 'สมชาย',
            'last_name'          => 'ใจดี',
            'salary'             => 20000,
            'overtime'           => 3000,
            'position_allowance' => 1500,
            'p4p_income'         => 5500,
            'living_allowance'   => 1000,
            'net_income'         => 99999,
        ]);
    }

    public function test_percent_has_no_default_and_must_be_supplied(): void
    {
        $this->actingAs($this->user)
            ->getJson("/api/hr/reserve-fund?import_id={$this->import->id}")
            ->assertOk()
            ->assertJsonPath('summary.percent', null)
            ->assertJsonPath('summary.total_income_base', 30000)
            ->assertJsonPath('summary.total_reserve', null);
    }

    public function test_custom_percent_is_applied(): void
    {
        $this->actingAs($this->user)
            ->getJson("/api/hr/reserve-fund?import_id={$this->import->id}&percent=5")
            ->assertOk()
            ->assertJsonPath('summary.percent', 5)
            ->assertJsonPath('summary.total_reserve', 1500);
    }

    public function test_decimal_percent_is_supported(): void
    {
        $this->actingAs($this->user)
            ->getJson("/api/hr/reserve-fund?import_id={$this->import->id}&percent=2.5")
            ->assertOk()
            ->assertJsonPath('summary.percent', 2.5)
            ->assertJsonPath('summary.total_reserve', 750);
    }

    public function test_payroll_of_a_resigned_employee_is_not_counted(): void
    {
        $resigned = EmployeeStatus::create(['name' => 'ลาออก', 'sort_order' => 3]);

        $employee = Employee::create([
            'citizen_id' => '2222222222222',
            'first_name' => 'สมหญิง',
            'last_name'  => 'รักดี',
            'status_id'  => $resigned->id,
        ]);

        Payroll::create([
            'import_id'  => $this->import->id,
            'citizen_id' => $employee->citizen_id,
            'first_name' => 'สมหญิง',
            'last_name'  => 'รักดี',
            'salary'     => 40000,
        ]);

        $this->actingAs($this->user)
            ->getJson("/api/hr/reserve-fund?import_id={$this->import->id}")
            ->assertOk()
            ->assertJsonPath('summary.total_income_base', 30000)
            ->assertJsonPath('summary.total_employees', 1);
    }

    public function test_payroll_of_an_employee_on_leave_is_not_counted(): void
    {
        $onLeave = EmployeeStatus::create(['name' => 'ลาศึกษาต่อ', 'sort_order' => 2]);

        $employee = Employee::create([
            'citizen_id' => '3333333333333',
            'first_name' => 'สมปอง',
            'last_name'  => 'เรียบดี',
            'status_id'  => $onLeave->id,
        ]);

        Payroll::create([
            'import_id'  => $this->import->id,
            'citizen_id' => $employee->citizen_id,
            'first_name' => 'สมปอง',
            'last_name'  => 'เรียบดี',
            'salary'     => 50000,
        ]);

        $this->actingAs($this->user)
            ->getJson("/api/hr/reserve-fund?import_id={$this->import->id}")
            ->assertOk()
            ->assertJsonPath('summary.total_income_base', 30000);
    }

    public function test_payroll_that_cannot_be_matched_to_an_employee_is_not_counted(): void
    {
        Payroll::create([
            'import_id'  => $this->import->id,
            'citizen_id' => '9999999999999', // ไม่มีในทะเบียนบุคลากร
            'first_name' => 'ไม่ทราบ',
            'last_name'  => 'ชื่อ',
            'salary'     => 70000,
        ]);

        $this->actingAs($this->user)
            ->getJson("/api/hr/reserve-fund?import_id={$this->import->id}")
            ->assertOk()
            ->assertJsonPath('summary.total_income_base', 30000);
    }

    public function test_payroll_summary_rows_without_citizen_id_are_not_counted(): void
    {
        // แถว "รวมยอด" ท้ายไฟล์ payroll — ไม่มีเลขบัตรประชาชน
        // ถ้านับด้วยจะทำให้ฐานคำนวณเงินสำรองพองเป็นสองเท่า
        Payroll::create([
            'import_id'  => $this->import->id,
            'citizen_id' => null,
            'first_name' => null,
            'last_name'  => null,
            'salary'     => 20000,
            'net_income' => 20000,
        ]);

        $this->actingAs($this->user)
            ->getJson("/api/hr/reserve-fund?import_id={$this->import->id}")
            ->assertOk()
            ->assertJsonPath('summary.total_income_base', 30000)
            ->assertJsonPath('summary.total_employees', 1);
    }

    public function test_income_breakdown_is_returned(): void
    {
        $this->actingAs($this->user)
            ->getJson("/api/hr/reserve-fund?import_id={$this->import->id}")
            ->assertOk()
            ->assertJsonPath('summary.income_breakdown.salary', 20000)
            ->assertJsonPath('summary.income_breakdown.overtime', 3000)
            ->assertJsonPath('summary.income_breakdown.position_allowance', 1500)
            ->assertJsonPath('summary.income_breakdown.p4p_income', 5500);
    }

    public function test_percent_out_of_range_is_rejected(): void
    {
        $this->actingAs($this->user)
            ->getJson("/api/hr/reserve-fund?percent=101")
            ->assertStatus(422)
            ->assertJsonValidationErrors('percent');

        $this->actingAs($this->user)
            ->getJson("/api/hr/reserve-fund?percent=-1")
            ->assertStatus(422)
            ->assertJsonValidationErrors('percent');
    }

    public function test_calculation_is_saved_per_fiscal_year(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/hr/reserve-fund/calculations', [
                'import_id'    => $this->import->id,
                'percent'      => 3,
                'fiscal_year'  => 2569,
                'period_month' => 10,
            ])
            ->assertCreated()
            ->assertJsonPath('data.fiscal_year', 2569)
            ->assertJsonPath('data.period_month', 10)
            ->assertJsonPath('data.period_year', 2568)
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.total_reserve', '900.00');

        $this->actingAs($this->user)
            ->postJson('/api/hr/reserve-fund/calculations', [
                'import_id'    => $this->import->id,
                'percent'      => 5,
                'fiscal_year'  => 2570,
                'period_month' => 10,
            ])
            ->assertCreated()
            ->assertJsonPath('data.fiscal_year', 2570)
            ->assertJsonPath('data.total_reserve', '1500.00');

        $this->assertDatabaseCount('reserve_fund_calculations', 2);
    }

    public function test_saving_same_fiscal_year_and_period_updates_record(): void
    {
        $payload = [
            'import_id'    => $this->import->id,
            'percent'      => 3,
            'fiscal_year'  => 2569,
            'period_month' => 11,
        ];

        $this->actingAs($this->user)->postJson('/api/hr/reserve-fund/calculations', $payload)
            ->assertCreated();

        $this->actingAs($this->user)
            ->postJson('/api/hr/reserve-fund/calculations', array_merge($payload, ['percent' => 4]))
            ->assertOk()
            ->assertJsonPath('data.total_reserve', '1200.00');

        $this->assertDatabaseCount('reserve_fund_calculations', 1);
    }

    public function test_period_month_is_required_when_saving(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/hr/reserve-fund/calculations', [
                'import_id'   => $this->import->id,
                'percent'     => 3,
                'fiscal_year' => 2569,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('period_month');
    }

    public function test_summary_returns_saved_result_of_selected_fiscal_year(): void
    {
        $this->actingAs($this->user)->postJson('/api/hr/reserve-fund/calculations', [
            'import_id'    => $this->import->id,
            'percent'      => 3,
            'fiscal_year'  => 2569,
            'period_month' => 10,
        ])->assertCreated();

        $this->actingAs($this->user)
            ->getJson("/api/hr/reserve-fund?import_id={$this->import->id}&fiscal_year=2569")
            ->assertOk()
            ->assertJsonPath('summary.fiscal_year', 2569)
            ->assertJsonPath('summary.saved.percent', 3)
            ->assertJsonPath('summary.saved.total_reserve', 900);

        // ปีงบอื่นยังไม่มีผลที่บันทึกไว้ และไม่กระทบข้อมูลเดิม
        $this->actingAs($this->user)
            ->getJson("/api/hr/reserve-fund?import_id={$this->import->id}&fiscal_year=2570")
            ->assertOk()
            ->assertJsonPath('summary.fiscal_year', 2570)
            ->assertJsonPath('summary.saved', null);
    }

    public function test_calculations_can_be_filtered_by_fiscal_year(): void
    {
        foreach ([2569, 2570] as $year) {
            $this->actingAs($this->user)->postJson('/api/hr/reserve-fund/calculations', [
                'import_id'    => $this->import->id,
                'percent'      => 3,
                'fiscal_year'  => $year,
                'period_month' => 10,
            ])->assertCreated();
        }

        $this->actingAs($this->user)
            ->getJson('/api/hr/reserve-fund/calculations?fiscal_year=2570')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.fiscal_year', 2570);
    }

    public function test_fiscal_years_returns_only_years_with_saved_calculations(): void
    {
        // ยังไม่มีการบันทึก → ไม่มีปีให้เลือก
        $this->actingAs($this->user)
            ->getJson('/api/hr/reserve-fund/fiscal-years')
            ->assertOk()
            ->assertExactJson(['data' => []]);

        foreach ([2569, 2570] as $year) {
            $this->actingAs($this->user)->postJson('/api/hr/reserve-fund/calculations', [
                'import_id'    => $this->import->id,
                'percent'      => 3,
                'fiscal_year'  => $year,
                'period_month' => 10,
            ])->assertCreated();
        }

        // บันทึกซ้ำปีเดิม ไม่ควรทำให้ปีซ้ำใน dropdown
        $this->actingAs($this->user)->postJson('/api/hr/reserve-fund/calculations', [
            'import_id'    => $this->import->id,
            'percent'      => 4,
            'fiscal_year'  => 2569,
            'period_month' => 10,
        ])->assertOk();

        $this->actingAs($this->user)
            ->getJson('/api/hr/reserve-fund/fiscal-years')
            ->assertOk()
            ->assertExactJson(['data' => [2570, 2569]]);
    }
}
