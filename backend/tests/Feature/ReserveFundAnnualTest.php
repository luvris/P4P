<?php

namespace Tests\Feature;

use App\Models\Duty;
use App\Models\Employee;
use App\Models\EmployeeStatus;
use App\Models\Group;
use App\Models\Import;
use App\Models\Payroll;
use App\Models\ReserveFundCalculation;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * เงินสำรองรายปี — รวม payroll ทุกงวดของปีงบประมาณ เป็นผลลัพธ์เดียว
 */
class ReserveFundAnnualTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'name'     => 'HR Annual',
            'username' => 'hr_annual',
            'email'    => 'hr_annual@example.test',
            'password' => bcrypt('secret-password'),
            'role'     => 'hr',
        ]);

        $duty = Duty::create(['name' => 'ด้านการพยาบาล']);
        $group = Group::create(['name' => 'กลุ่มงานการพยาบาลผู้ป่วยนอก', 'duty_id' => $duty->id]);
        $work = Work::create(['name' => 'งานผู้ป่วยนอก', 'group_id' => $group->id]);

        $working = EmployeeStatus::create(['name' => 'ปฏิบัติงานอยู่', 'sort_order' => 1]);

        Employee::create([
            'citizen_id' => '1111111111111',
            'first_name' => 'สมชาย',
            'last_name'  => 'ใจดี',
            'duty_id'    => $duty->id,
            'group_id'   => $group->id,
            'work_id'    => $work->id,
            'status_id'  => $working->id,
        ]);
    }

    /**
     * สร้างชุดข้อมูล payroll หนึ่งงวด
     *
     * @param  array{fiscal_year:int, period_month:int, period_year:int}|null  $period
     */
    protected function makeImport(float $salary, ?array $period = null): Import
    {
        $import = Import::create([
            'file_name'   => "payroll-{$salary}-" . ($period['period_month'] ?? 'x') . '.xlsx',
            'file_path'   => 'imports/payroll.xlsx',
            'file_type'   => 'xlsx',
            'uploaded_by' => $this->user->id,
            'status'      => 'completed',
            'import_type' => 'payroll',
            'fiscal_year' => $period['fiscal_year'] ?? null,
            'period_month' => $period['period_month'] ?? null,
            'period_year' => $period['period_year'] ?? null,
        ]);

        Payroll::create([
            'import_id'  => $import->id,
            'citizen_id' => '1111111111111',
            'first_name' => 'สมชาย',
            'last_name'  => 'ใจดี',
            'salary'     => $salary,
            // ฐานคำนวณเงินสำรองใช้ยอดรวมรายรับทั้งหมดรายบุคคล
            'total_income' => $salary,
        ]);

        return $import;
    }

    protected function annual(int $fiscalYear = 2569, ?float $percent = 3)
    {
        $query = "/api/hr/reserve-fund/annual?fiscal_year={$fiscalYear}";
        if ($percent !== null) {
            $query .= "&percent={$percent}";
        }

        return $this->actingAs($this->user)->getJson($query);
    }

    public function test_annual_totals_sum_every_period_in_the_fiscal_year(): void
    {
        $this->makeImport(10000, ['fiscal_year' => 2569, 'period_month' => 10, 'period_year' => 2568]);
        $this->makeImport(20000, ['fiscal_year' => 2569, 'period_month' => 11, 'period_year' => 2568]);

        // ปีงบอื่น — ต้องไม่ถูกนำมาบวก
        $this->makeImport(50000, ['fiscal_year' => 2570, 'period_month' => 10, 'period_year' => 2569]);

        $this->annual(2569, 3)
            ->assertOk()
            ->assertJsonPath('summary.total_income_base', 30000)
            ->assertJsonPath('summary.total_reserve', 900)
            ->assertJsonPath('summary.months_present', 2);
    }

    /**
     * จำนวนบุคลากรต้องนับคนไม่ซ้ำข้ามทุกงวด
     *
     * คนเดิมมีเงินเดือน 8 งวด = 8 แถวใน payroll ถ้านับแถวจะได้ 8 คน
     * แต่ทะเบียนบุคลากรมีคนนี้คนเดียว
     */
    public function test_annual_headcount_counts_each_person_once_across_periods(): void
    {
        $this->makeImport(10000, ['fiscal_year' => 2569, 'period_month' => 10, 'period_year' => 2568]);
        $this->makeImport(20000, ['fiscal_year' => 2569, 'period_month' => 11, 'period_year' => 2568]);
        $this->makeImport(30000, ['fiscal_year' => 2569, 'period_month' => 12, 'period_year' => 2568]);

        $response = $this->annual(2569, 3)->assertOk();

        // ยอดเงินยังรวมทุกงวด แต่จำนวนคนต้องเป็น 1
        $this->assertEqualsWithDelta(60000, $response->json('summary.total_income_base'), 0.001);
        $this->assertSame(1, $response->json('summary.total_employees'));

        // รายงวดยังรายงวดนับคนของงวดนั้น
        $months = collect($response->json('summary.months'))->keyBy('period_month');
        $this->assertSame(1, $months[10]['total_employees']);
        $this->assertSame(1, $months[12]['total_employees']);

        // ตารางแยกภารกิจ/งาน ต้องไม่นับคนเดียวซ้ำหลายงวด
        $this->assertSame(1, $response->json('data.duties.0.employee_count'));
        $this->assertSame(1, $response->json('data.duties.0.works.0.employee_count'));
    }

    /**
     * ไฟล์เดียวที่มีหลายเดือนปนกัน ต้องถูกแยกเป็นรายงวดจริง
     *
     * เดิมระบบถือว่า 1 ไฟล์ = 1 งวด ทำให้ยอดทั้งปีไปตกอยู่งวดเดียว
     */
    public function test_one_import_containing_many_months_is_split_by_its_own_periods(): void
    {
        // ไฟล์เดียว มี 3 งวดปนกัน (แต่ละงวดคนละเงิน)
        $import = Import::create([
            'file_name'   => 'payroll-year.xlsx',
            'file_path'   => 'imports/payroll.xlsx',
            'file_type'   => 'xlsx',
            'uploaded_by' => $this->user->id,
            'status'      => 'completed',
            'import_type' => 'payroll',
            // ไม่ระบุงวดที่ระดับไฟล์ — งวดอยู่ที่ระดับแถวตามรูปแบบใหม่
        ]);

        foreach ([[10, 10000], [11, 20000], [12, 30000]] as [$month, $salary]) {
            Payroll::create([
                'import_id'    => $import->id,
                'citizen_id'   => '1111111111111',
                'first_name'   => 'สมชาย',
                'last_name'    => 'ใจดี',
                'fiscal_year'  => 2569,
                'period_month' => $month,
                'salary'       => $salary,
                'total_income' => $salary,
            ]);
        }

        $response = $this->annual(2569, 3)->assertOk();

        $this->assertSame(3, $response->json('summary.months_present'));

        $months = collect($response->json('summary.months'))->keyBy('period_month');

        $this->assertEqualsWithDelta(10000, $months[10]['income_base'], 0.001);
        $this->assertEqualsWithDelta(20000, $months[11]['income_base'], 0.001);
        $this->assertEqualsWithDelta(30000, $months[12]['income_base'], 0.001);

        // คนเดียวกัน 3 งวด แต่นับเป็นคนเดียว
        foreach ([10, 11, 12] as $month) {
            $this->assertSame(1, $months[$month]['total_employees']);
        }

        $this->assertSame(1, $response->json('summary.total_employees'));

        // ยอดรวมทั้งปียังรวมทุกงวด
        $this->assertEqualsWithDelta(60000, $response->json('summary.total_income_base'), 0.001);
    }

    public function test_annual_reports_all_twelve_periods_with_completeness(): void
    {
        $this->makeImport(10000, ['fiscal_year' => 2569, 'period_month' => 10, 'period_year' => 2568]);

        $response = $this->annual(2569, 3)->assertOk();

        $months = $response->json('summary.months');

        $this->assertCount(12, $months);
        $this->assertTrue($months[0]['has_data']);
        $this->assertSame(10, $months[0]['period_month']);
        $this->assertFalse($months[1]['has_data']);
        $this->assertCount(11, $response->json('summary.months_missing'));
    }

    public function test_period_is_derived_from_upload_date_when_not_tagged(): void
    {
        $import = $this->makeImport(10000);
        // อัปโหลดเดือน ต.ค. 2026 = ปีงบ 2570 (ปีงบเริ่ม ต.ค.)
        $import->forceFill(['created_at' => '2026-10-05 09:00:00'])->save();

        $this->annual(2570, 3)
            ->assertOk()
            ->assertJsonPath('summary.months_present', 1)
            ->assertJsonPath('summary.months.0.period_month', 10)
            ->assertJsonPath('summary.months.0.period_source', 'inferred');
    }

    public function test_duplicate_import_of_same_period_counts_once(): void
    {
        $this->makeImport(10000, ['fiscal_year' => 2569, 'period_month' => 10, 'period_year' => 2568]);
        $this->makeImport(10000, ['fiscal_year' => 2569, 'period_month' => 10, 'period_year' => 2568]);

        // เดือนเดียวกันมีสองชุดข้อมูล → นับชุดเดียว
        $this->annual(2569, 3)
            ->assertOk()
            ->assertJsonPath('summary.months_present', 1)
            ->assertJsonPath('summary.total_income_base', 10000);
    }

    public function test_saving_annual_creates_one_row_per_fiscal_year(): void
    {
        $this->makeImport(10000, ['fiscal_year' => 2569, 'period_month' => 10, 'period_year' => 2568]);
        $this->makeImport(20000, ['fiscal_year' => 2569, 'period_month' => 11, 'period_year' => 2568]);

        $first = $this->actingAs($this->user)
            ->postJson('/api/hr/reserve-fund/annual', ['fiscal_year' => 2569, 'percent' => 3])
            ->assertCreated()
            ->assertJsonPath('data.total_income_base', '30000.00')
            ->assertJsonPath('data.total_reserve', '900.00')
            ->json('data.id');

        // บันทึกซ้ำ = อัปเดตแถวเดิม ไม่สร้างรายการใหม่
        $second = $this->actingAs($this->user)
            ->postJson('/api/hr/reserve-fund/annual', ['fiscal_year' => 2569, 'percent' => 5])
            ->assertOk()
            ->assertJsonPath('data.total_reserve', '1500.00')
            ->json('data.id');

        $this->assertSame($first, $second);
        $this->assertSame(1, ReserveFundCalculation::where('fiscal_year', 2569)->count());
    }

    public function test_confirmed_annual_cannot_be_overwritten(): void
    {
        $this->makeImport(10000, ['fiscal_year' => 2569, 'period_month' => 10, 'period_year' => 2568]);

        $id = $this->actingAs($this->user)
            ->postJson('/api/hr/reserve-fund/annual', ['fiscal_year' => 2569, 'percent' => 3])
            ->json('data.id');

        $this->actingAs($this->user)
            ->postJson("/api/hr/reserve-fund/calculations/{$id}/confirm")
            ->assertOk()
            ->assertJsonPath('data.status', 'confirmed');

        $this->actingAs($this->user)
            ->postJson('/api/hr/reserve-fund/annual', ['fiscal_year' => 2569, 'percent' => 9])
            ->assertStatus(409);

        $this->assertSame('300.00', ReserveFundCalculation::find($id)->total_reserve);
    }

    public function test_annual_percent_is_required_when_saving(): void
    {
        $this->makeImport(10000, ['fiscal_year' => 2569, 'period_month' => 10, 'period_year' => 2568]);

        $this->actingAs($this->user)
            ->postJson('/api/hr/reserve-fund/annual', ['fiscal_year' => 2569])
            ->assertStatus(422)
            ->assertJsonValidationErrors('percent');
    }

    public function test_annual_cannot_be_saved_without_payroll(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/hr/reserve-fund/annual', ['fiscal_year' => 2569, 'percent' => 3])
            ->assertStatus(422);
    }

    public function test_annual_breakdown_groups_by_duty_and_work(): void
    {
        $this->makeImport(10000, ['fiscal_year' => 2569, 'period_month' => 10, 'period_year' => 2568]);

        $response = $this->annual(2569, 3)->assertOk();

        $duties = $response->json('data.duties');

        $this->assertCount(1, $duties);
        $this->assertSame('ด้านการพยาบาล', $duties[0]['name']);
        $this->assertSame('งานผู้ป่วยนอก', $duties[0]['works'][0]['name']);
        $this->assertSame('กลุ่มงานการพยาบาลผู้ป่วยนอก', $duties[0]['works'][0]['group_name']);
        $this->assertSame(300.0, (float) $duties[0]['reserve_amount']);
    }
}
