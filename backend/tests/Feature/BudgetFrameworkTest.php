<?php

namespace Tests\Feature;

use App\Models\BudgetFramework;
use App\Models\Employee;
use App\Models\EmployeeStatus;
use App\Models\Import;
use App\Models\Payroll;
use App\Models\Position;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * กรอบวงเงิน P4P — คำนวณจาก payrolls + employees, จัดกลุ่มตามตำแหน่ง, ส่งออก Excel
 */
class BudgetFrameworkTest extends TestCase
{
    use RefreshDatabase;

    protected User $finance;

    protected User $hr;

    protected EmployeeStatus $active;

    protected function setUp(): void
    {
        parent::setUp();

        $this->finance = User::create([
            'name' => 'Finance',
            'username' => 'finance_bf',
            'email' => 'finance_bf@example.test',
            'password' => bcrypt('secret-password'),
            'role' => 'finance',
        ]);

        $this->hr = User::create([
            'name' => 'HR',
            'username' => 'hr_bf',
            'email' => 'hr_bf@example.test',
            'password' => bcrypt('secret-password'),
            'role' => 'hr',
        ]);

        $this->active = EmployeeStatus::create(['name' => EmployeeStatus::ACTIVE_NAME, 'sort_order' => 1]);
    }

    /** สร้างบุคลากรที่ปฏิบัติงานอยู่ 1 คน ตามชื่อตำแหน่ง */
    protected function makeEmployee(string $positionName, string $citizenId): Employee
    {
        $position = Position::firstOrCreate(['name' => $positionName]);

        return Employee::create([
            'citizen_id' => $citizenId,
            'first_name' => 'ทดสอบ',
            'last_name' => $citizenId,
            'position_id' => $position->id,
            'status_id' => $this->active->id,
        ]);
    }

    /** สร้าง payroll ของงวดหนึ่ง รวมทุกคนที่สร้างไว้ */
    protected function makePeriod(int $fiscalYear, int $periodMonth, float $perPerson): void
    {
        $import = Import::create([
            'file_name' => "payroll-fy{$fiscalYear}-m{$periodMonth}.xlsx",
            'file_path' => 'imports/payroll.xlsx',
            'file_type' => 'xlsx',
            'uploaded_by' => $this->finance->id,
            'status' => 'completed',
            'import_type' => 'payroll',
            'fiscal_year' => $fiscalYear,
            'period_month' => $periodMonth,
        ]);        foreach (Employee::with('position:id,name')->get() as $employee) {
            Payroll::create([
                'import_id'     => $import->id,
                'citizen_id'    => $employee->citizen_id,
                'first_name'    => 'ทดสอบ',
                'last_name'     => $employee->citizen_id,
                // จำนวนคนต่อกลุ่มนับจากคอลัมน์ "ตำแหน่ง" ในไฟล์ ไม่ใช่ทะเบียนบุคลากร
                'position_name' => $employee->position?->name,
                'fiscal_year'   => $fiscalYear,
                'period_month'  => $periodMonth,
                'salary'        => $perPerson,
                'total_income'  => $perPerson,
            ]);
        }
    }

    /** สร้างชุดข้อมูลตัวอย่าง: 6 คน 5 กลุ่ม */
    protected function seedWorkforce(): void
    {
        $this->makeEmployee('นายแพทย์', '1111111111111');   // doctor (1)
        $this->makeEmployee('เภสัชกร', '2222222222222');    // pharmacist (0.65)
        $this->makeEmployee('พยาบาลวิชาชีพ', '3333333333333'); // nurse (0.54)
        $this->makeEmployee('พยาบาลวิชาชีพ', '4444444444444'); // nurse (0.54)
        $this->makeEmployee('เจ้าพนักงานการเงิน', '5555555555555'); // support_subdegree (0.35)
        $this->makeEmployee('พนักงานช่วยการพยาบาล', '6666666666666'); // service_other (0.35)
    }

    protected function preview(array $params = [])
    {
        $query = array_merge([
            'fiscal_year' => 2569,
            'labor_percent' => 3,
            'activity_ratio' => 70,
            'quality_ratio' => 30,
        ], $params);

        return $this->actingAs($this->finance)
            ->getJson('/api/finance/budget-frameworks/preview?'.http_build_query($query));
    }

    protected function groupBy(array $groups, string $code): array
    {
        foreach ($groups as $group) {
            if ($group['code'] === $code) {
                return $group;
            }
        }

        $this->fail("ไม่พบกลุ่มวิชาชีพ {$code}");
    }

    public function test_labor_cost_is_the_average_monthly_payroll_of_the_fiscal_year(): void
    {
        $this->seedWorkforce();
        $this->makePeriod(2569, 10, 100000); // 6 คน × 100,000 = 600,000
        $this->makePeriod(2569, 11, 200000); // 6 คน × 200,000 = 1,200,000

        $response = $this->preview()->assertOk();

        // (600,000 + 1,200,000) / 2 งวด = 900,000 ต่อเดือน
        $this->assertEqualsWithDelta(900000, $response->json('data.labor_cost.monthly'), 0.001);
        $this->assertEqualsWithDelta(10800000, $response->json('data.labor_cost.annual'), 0.001);
        $this->assertSame(2, $response->json('data.months_present'));

        // 3% ของค่าแรงต่อปี
        $this->assertEqualsWithDelta(324000, $response->json('data.p4p_annual'), 0.001);
        $this->assertEqualsWithDelta(226800, $response->json('data.activity_budget'), 0.001);
        $this->assertEqualsWithDelta(97200, $response->json('data.quality_budget'), 0.001);
    }

    public function test_headcount_is_grouped_by_position_into_professional_groups(): void
    {
        $this->seedWorkforce();
        $this->makePeriod(2569, 10, 100000);

        $groups = $this->preview()->assertOk()->json('data.groups');

        $this->assertSame(1, $this->groupBy($groups, 'doctor')['headcount']);
        $this->assertSame(1, $this->groupBy($groups, 'pharmacist')['headcount']);
        $this->assertSame(2, $this->groupBy($groups, 'nurse')['headcount']);
        $this->assertSame(1, $this->groupBy($groups, 'support_subdegree')['headcount']);
        $this->assertSame(1, $this->groupBy($groups, 'service_other')['headcount']);

        // 6 คนใน 5 กลุ่ม
        $this->assertSame(6, $this->preview()->json('data.total.headcount'));
    }

    public function test_activity_budget_is_shared_by_weighted_proportion(): void
    {
        $this->seedWorkforce();
        $this->makePeriod(2569, 10, 100000);
        $this->makePeriod(2569, 11, 200000);

        $data = $this->preview()->assertOk()->json('data');

        // ผลรวมสัดส่วน = ผลรวมน้ำหนักของทั้ง 9 กลุ่ม (ว่างหรือไม่ว่างก็แสดง) = 5.08 ตามเอกสารต้นแบบ
        $this->assertEqualsWithDelta(5.08, $data['total']['weight'], 0.001);

        // ผลรวมถ่วงน้ำหนัก = doctor 1.00×1 + pharmacist 0.65×1 + nurse 0.54×2 + support 0.35×1 + service 0.35×1
        $this->assertEqualsWithDelta(3.43, $data['total']['weighted'], 0.001);

        // 226,800 ÷ 3.43 = 66,122.449…
        $this->assertEqualsWithDelta(66122.449, $data['unit_rate_year'], 0.01);

        $doctor = $this->groupBy($data['groups'], 'doctor');
        $this->assertEqualsWithDelta(66122.45, $doctor['amount_year'], 0.001);
        $this->assertEqualsWithDelta(66122.45, $doctor['avg_year'], 0.001);

        // ยอดรวมของทุกกลุ่มต้องเท่ากับวงเงิน Activity (ต่างกันไม่เกินเศษจากการปัด)
        $this->assertEqualsWithDelta(226800, $data['total']['amount_year'], 0.10);
    }

    public function test_weights_can_be_overridden_from_the_request(): void
    {
        $this->seedWorkforce();
        $this->makePeriod(2569, 10, 100000);
        $this->makePeriod(2569, 11, 200000);

        $data = $this->preview(['weights' => ['doctor' => 2.00]])->assertOk()->json('data');

        $doctor = $this->groupBy($data['groups'], 'doctor');
        $this->assertEqualsWithDelta(2.00, $doctor['weight'], 0.001);
        $this->assertEqualsWithDelta(2.00, $doctor['weighted'], 0.001);
        // 2.00 แทน 1.00 ทำให้ผลรวมถ่วงน้ำหนักเพิ่มขึ้นเป็น 4.43
        $this->assertEqualsWithDelta(4.43, $data['total']['weighted'], 0.001);
    }

    public function test_unknown_positions_are_reported_as_unclassified(): void
    {
        $this->seedWorkforce();
        $this->makeEmployee('นักบินอวกาศ', '9999999999999');
        $this->makePeriod(2569, 10, 100000);

        $groups = $this->preview()->assertOk()->json('data.groups');

        $unclassified = $this->groupBy($groups, 'unclassified');
        $this->assertSame(1, $unclassified['headcount']);
        $this->assertSame('นักบินอวกาศ', $unclassified['positions'][0]['name']);
    }

    public function test_saving_creates_one_row_per_fiscal_year(): void
    {
        $this->seedWorkforce();
        $this->makePeriod(2569, 10, 100000);

        $first = $this->actingAs($this->finance)
            ->postJson('/api/finance/budget-frameworks', ['fiscal_year' => 2569, 'labor_percent' => 3])
            ->assertCreated()
            ->json('data.id');

        $second = $this->actingAs($this->finance)
            ->postJson('/api/finance/budget-frameworks', ['fiscal_year' => 2569, 'labor_percent' => 5])
            ->assertOk()
            ->json('data.id');

        $this->assertSame($first, $second);
        $this->assertSame(1, BudgetFramework::where('fiscal_year', 2569)->count());
        $this->assertSame('5.00', BudgetFramework::find($first)->labor_percent);
    }

    public function test_position_groups_endpoint_lists_positions_from_the_file(): void
    {
        $this->seedWorkforce();
        $this->makePeriod(2569, 10, 100000);

        $response = $this->actingAs($this->finance)
            ->getJson('/api/finance/budget-frameworks/position-groups?fiscal_year=2569')
            ->assertOk();

        $positions = collect($response->json('data.positions'))->keyBy('position_name');

        $this->assertSame(2, $positions['พยาบาลวิชาชีพ']['headcount']);
        $this->assertSame('nurse', $positions['พยาบาลวิชาชีพ']['group_code']);
        $this->assertFalse($positions['พยาบาลวิชาชีพ']['is_overridden']);

        // รายการกลุ่มสำหรับ dropdown ครบ 9 กลุ่ม
        $this->assertCount(9, $response->json('data.groups'));
    }

    public function test_position_group_override_changes_the_headcount_group(): void
    {
        $this->seedWorkforce();
        $this->makePeriod(2569, 10, 100000);

        // เดิม "เภสัชกร" อยู่กลุ่ม pharmacist
        $this->assertSame(1, $this->groupBy($this->preview()->json('data.groups'), 'pharmacist')['headcount']);

        $this->actingAs($this->finance)
            ->putJson('/api/finance/budget-frameworks/position-groups', [
                'fiscal_year' => 2569,
                'mappings'    => ['เภสัชกร' => 'professional_degree'],
            ])
            ->assertOk();

        $groups = $this->preview()->assertOk()->json('data.groups');

        $this->assertSame(1, $this->groupBy($groups, 'professional_degree')['headcount']);
        // กลุ่มเดิมไม่มีคนแล้ว
        $this->assertSame(0, $this->groupBy($groups, 'pharmacist')['headcount']);
    }

    public function test_saving_requires_working_employees(): void
    {
        $this->actingAs($this->finance)
            ->postJson('/api/finance/budget-frameworks', ['fiscal_year' => 2569])
            ->assertStatus(422);
    }

    public function test_hr_role_cannot_open_the_budget_framework(): void
    {
        $this->actingAs($this->hr)
            ->getJson('/api/finance/budget-frameworks/preview?fiscal_year=2569')
            ->assertStatus(403);
    }

    /** อ่านค่าในชีตแรกของไฟล์ xlsx ที่ export กลับมา */
    protected function exportedSheet(array $params = [])
    {
        $response = $this->actingAs($this->finance)
            ->get('/api/finance/budget-frameworks/export?'.http_build_query(array_merge([
                'fiscal_year' => 2569,
                'labor_percent' => 3,
                'activity_ratio' => 70,
                'quality_ratio' => 30,
            ], $params)))
            ->assertOk();

        $content = $response->streamedContent();

        $path = tempnam(sys_get_temp_dir(), 'bf_export_').'.xlsx';
        file_put_contents($path, $content);

        try {
            return IOFactory::load($path)->getActiveSheet();
        } finally {
            @unlink($path);
        }
    }

    public function test_export_returns_real_xlsx_binary(): void
    {
        $this->seedWorkforce();
        $this->makePeriod(2569, 10, 100000);

        $response = $this->actingAs($this->finance)
            ->get('/api/finance/budget-frameworks/export?fiscal_year=2569')
            ->assertOk();

        $this->assertSame('PK', substr($response->streamedContent(), 0, 2), 'response ไม่ใช่ไฟล์ xlsx');
        $this->assertStringContainsString('spreadsheetml.sheet', $response->headers->get('content-type'));
        $this->assertStringContainsString('budget-framework-FY2569.xlsx', $response->headers->get('content-disposition'));
    }

    public function test_export_contains_the_document_layout(): void
    {
        $this->seedWorkforce();
        $this->makePeriod(2569, 10, 100000);

        $sheet = $this->exportedSheet();

        $this->assertSame(
            'กรอบวงเงิน P4P ของโรงพยาบาลประสาทเชียงใหม่ ปีงบประมาณ พ.ศ. 2569',
            $sheet->getCell('A1')->getValue()
        );

        // ค้นหาหัวตารางและแถวข้อมูลในชีต
        $values = [];
        foreach ($sheet->toArray(null, true, false, false) as $row) {
            $values[] = array_map(fn ($cell) => (string) $cell, $row);
        }
        $flat = array_merge(...$values);

        $this->assertContains('จำนวนคน (คน)', $flat);
        $this->assertContains('สัดส่วน (คน)', $flat);
        $this->assertContains('รวมเงินวิชาชีพต่อปี', $flat);
        $this->assertContains('รวมเงิน P4P ต่อหน่วย (KPI)', $flat);
        $this->assertContains('รวม', $flat);

        // ชื่อกลุ่มวิชาชีพต้องปรากฏบนเอกสาร
        $this->assertContains('เภสัชกร', $flat);
    }

    public function test_saved_framework_export_matches_the_saved_numbers(): void
    {
        $this->seedWorkforce();
        $this->makePeriod(2569, 10, 100000);

        $id = $this->actingAs($this->finance)
            ->postJson('/api/finance/budget-frameworks', ['fiscal_year' => 2569, 'labor_percent' => 5])
            ->assertCreated()
            ->json('data.id');

        $response = $this->actingAs($this->finance)
            ->get("/api/finance/budget-frameworks/{$id}/export")
            ->assertOk();

        $content = $response->streamedContent();
        $path = tempnam(sys_get_temp_dir(), 'bf_saved_').'.xlsx';
        file_put_contents($path, $content);

        try {
            $sheet = IOFactory::load($path)->getActiveSheet();

            // ค่าแรงต่อปี 7,200,000 × 5% = 360,000 (ถ้าใช้ 3% จะได้ 216,000)
            $hasP4p = collect($sheet->toArray(null, true, false, false))
                ->flatten()
                ->contains(fn ($cell) => is_numeric($cell) && abs((float) $cell - 360000) < 0.01);

            $this->assertTrue($hasP4p, 'ไม่พบยอด P4P ต่อปี 360,000 ในไฟล์ที่บันทึกไว้');
        } finally {
            @unlink($path);
        }
    }
}
