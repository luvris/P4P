<?php

namespace Tests\Feature;

use App\Models\Duty;
use App\Models\Employee;
use App\Models\Group;
use App\Models\Import;
use App\Models\Payroll;
use App\Models\ReserveFundCalculation;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * เงินสำรองรายงวด + ยอดสะสมของปีงบประมาณ
 */
class ReserveFundAccumulationTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'name'     => 'HR Tester',
            'username' => 'hr_accum',
            'email'    => 'hr_accum@example.test',
            'password' => bcrypt('secret-password'),
            'role'     => 'hr',
        ]);

        $duty = Duty::create(['name' => 'ด้านการพยาบาล']);
        $group = Group::create(['name' => 'กลุ่มงานการพยาบาลผู้ป่วยนอก', 'duty_id' => $duty->id]);
        Work::create(['name' => 'งานผู้ป่วยนอก', 'group_id' => $group->id]);

        Employee::create([
            'citizen_id' => '1111111111111',
            'first_name' => 'สมชาย',
            'last_name'  => 'ใจดี',
            'duty_id'    => $duty->id,
            'group_id'   => $group->id,
        ]);
    }

    /**
     * สร้างชุดข้อมูล payroll หนึ่งงวด — ฐานคำนวณ = salary ที่ส่งเข้ามา
     */
    protected function makeImport(float $salary): Import
    {
        $import = Import::create([
            'file_name'   => "payroll-{$salary}.xlsx",
            'file_path'   => 'imports/payroll.xlsx',
            'file_type'   => 'xlsx',
            'uploaded_by' => $this->user->id,
            'status'      => 'completed',
        ]);

        Payroll::create([
            'import_id'  => $import->id,
            'citizen_id' => '1111111111111',
            'first_name' => 'สมชาย',
            'last_name'  => 'ใจดี',
            'salary'     => $salary,
        ]);

        return $import;
    }

    protected function savePeriod(Import $import, int $month, float $percent, int $fiscalYear = 2569)
    {
        return $this->actingAs($this->user)->postJson('/api/hr/reserve-fund/calculations', [
            'import_id'    => $import->id,
            'percent'      => $percent,
            'fiscal_year'  => $fiscalYear,
            'period_month' => $month,
        ]);
    }

    protected function confirm(int $id)
    {
        return $this->actingAs($this->user)
            ->postJson("/api/hr/reserve-fund/calculations/{$id}/confirm");
    }

    public function test_accumulated_total_counts_only_confirmed_periods(): void
    {
        $oct = $this->makeImport(10000);
        $nov = $this->makeImport(20000);

        $octId = $this->savePeriod($oct, 10, 3)->assertCreated()->json('data.id');
        $this->savePeriod($nov, 11, 3)->assertCreated();

        // ยังไม่ยืนยัน → ยอดสะสมเป็น 0
        $this->actingAs($this->user)
            ->getJson('/api/hr/reserve-fund/accumulated?fiscal_year=2569')
            ->assertOk()
            ->assertJsonPath('data.confirmed_periods', 0)
            ->assertJsonPath('data.total_reserve', 0);

        // ยืนยันงวด ต.ค. → นับเฉพาะงวดนั้น (10000 * 3% = 300)
        $this->confirm($octId)
            ->assertOk()
            ->assertJsonPath('accumulated.confirmed_periods', 1)
            ->assertJsonPath('accumulated.total_reserve', 300);
    }

    public function test_accumulated_sums_multiple_confirmed_periods(): void
    {
        $oct = $this->makeImport(10000);
        $nov = $this->makeImport(20000);

        $octId = $this->savePeriod($oct, 10, 3)->json('data.id');
        $novId = $this->savePeriod($nov, 11, 5)->json('data.id');

        $this->confirm($octId)->assertOk();

        // 10000*3% = 300, 20000*5% = 1000
        $this->confirm($novId)
            ->assertOk()
            ->assertJsonPath('accumulated.confirmed_periods', 2)
            ->assertJsonPath('accumulated.total_reserve', 1300)
            ->assertJsonPath('accumulated.total_income_base', 30000)
            ->assertJsonPath('accumulated.total_periods', 12);
    }

    public function test_confirming_same_period_twice_does_not_double_count(): void
    {
        $oct = $this->makeImport(10000);
        $octId = $this->savePeriod($oct, 10, 3)->json('data.id');

        $this->confirm($octId)->assertOk();

        $this->confirm($octId)
            ->assertOk()
            ->assertJsonPath('accumulated.confirmed_periods', 1)
            ->assertJsonPath('accumulated.total_reserve', 300);
    }

    public function test_confirmed_period_cannot_be_overwritten_automatically(): void
    {
        $oct = $this->makeImport(10000);
        $octId = $this->savePeriod($oct, 10, 3)->json('data.id');

        $this->confirm($octId)->assertOk();

        // บันทึกงวดเดิมซ้ำต้องถูกปฏิเสธ ข้อมูลที่ยืนยันไว้ไม่เปลี่ยน
        $this->savePeriod($oct, 10, 9)->assertStatus(409);

        $this->assertSame('300.00', ReserveFundCalculation::find($octId)->total_reserve);
    }

    public function test_unconfirm_removes_period_from_accumulated_without_deleting(): void
    {
        $oct = $this->makeImport(10000);
        $octId = $this->savePeriod($oct, 10, 3)->json('data.id');

        $this->confirm($octId)->assertOk();

        $this->actingAs($this->user)
            ->postJson("/api/hr/reserve-fund/calculations/{$octId}/unconfirm")
            ->assertOk()
            ->assertJsonPath('accumulated.confirmed_periods', 0)
            ->assertJsonPath('accumulated.total_reserve', 0);

        // ข้อมูลผลคำนวณยังอยู่ ไม่ถูกลบ
        $this->assertDatabaseHas('reserve_fund_calculations', [
            'id'     => $octId,
            'status' => 'draft',
        ]);
    }

    public function test_accumulated_is_scoped_per_fiscal_year(): void
    {
        $a = $this->makeImport(10000);
        $b = $this->makeImport(20000);

        $idA = $this->savePeriod($a, 10, 3, 2569)->json('data.id');
        $idB = $this->savePeriod($b, 10, 3, 2570)->json('data.id');

        $this->confirm($idA)->assertOk();
        $this->confirm($idB)->assertOk();

        $this->actingAs($this->user)
            ->getJson('/api/hr/reserve-fund/accumulated?fiscal_year=2569')
            ->assertOk()
            ->assertJsonPath('data.total_reserve', 300);

        $this->actingAs($this->user)
            ->getJson('/api/hr/reserve-fund/accumulated?fiscal_year=2570')
            ->assertOk()
            ->assertJsonPath('data.total_reserve', 600);
    }

    public function test_periods_are_ordered_by_fiscal_month_starting_october(): void
    {
        $jan = $this->makeImport(10000);
        $oct = $this->makeImport(20000);

        // บันทึก ม.ค. ก่อน แล้วค่อย ต.ค. — ผลลัพธ์ต้องเรียง ต.ค. ก่อน
        $janId = $this->savePeriod($jan, 1, 3)->json('data.id');
        $octId = $this->savePeriod($oct, 10, 3)->json('data.id');

        $this->confirm($janId)->assertOk();

        $this->confirm($octId)
            ->assertOk()
            ->assertJsonPath('accumulated.periods.0.period_month', 10)
            ->assertJsonPath('accumulated.periods.1.period_month', 1);
    }

    public function test_period_year_follows_thai_fiscal_year_boundary(): void
    {
        $import = $this->makeImport(10000);

        // ปีงบ 2569 = ต.ค. 2568 ถึง ก.ย. 2569
        $this->savePeriod($import, 10, 3, 2569)
            ->assertCreated()
            ->assertJsonPath('data.period_year', 2568);

        $this->savePeriod($import, 9, 3, 2569)
            ->assertCreated()
            ->assertJsonPath('data.period_year', 2569);
    }

    public function test_summary_includes_accumulated_for_selected_fiscal_year(): void
    {
        $import = $this->makeImport(10000);
        $id = $this->savePeriod($import, 10, 3)->json('data.id');
        $this->confirm($id)->assertOk();

        $this->actingAs($this->user)
            ->getJson("/api/hr/reserve-fund?import_id={$import->id}&fiscal_year=2569")
            ->assertOk()
            ->assertJsonPath('accumulated.total_reserve', 300)
            ->assertJsonPath('accumulated.confirmed_periods', 1);
    }
}
