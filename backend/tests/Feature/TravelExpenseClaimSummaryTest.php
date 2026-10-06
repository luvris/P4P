<?php

namespace Tests\Feature;

use App\Models\Duty;
use App\Models\Employee;
use App\Models\Group;
use App\Models\TravelExpenseClaim;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * สรุปผลการเบิกค่าใช้จ่าย — ยอดแยกตาม ภารกิจ → กลุ่มงาน → งาน
 *
 * โครงข้อมูลทดสอบ (ปีงบประมาณ 2569):
 *  ภารกิจ A (dutyA) → กลุ่มงาน B (groupB) → งาน C (workC) / งาน D (workD)
 *  ภารกิจ X (dutyX) → กลุ่มงาน Y (groupY) → งาน Z (workZ)
 *  orphan — ไม่ผูกสังกัดใดเลย (หมวด "ไม่ระบุสังกัด")
 */
class TravelExpenseClaimSummaryTest extends TestCase
{
    use RefreshDatabase;

    protected User $finance;

    protected Duty $dutyA;
    protected Duty $dutyX;
    protected Group $groupB;
    protected Group $groupY;
    protected Work $workC;
    protected Work $workD;
    protected Work $workZ;

    protected function setUp(): void
    {
        parent::setUp();

        $this->finance = User::create([
            'name'     => 'Finance Staff',
            'username' => 'finance_summary',
            'email'    => 'finance_summary@example.test',
            'password' => bcrypt('secret-password'),
            'role'     => 'finance',
        ]);

        $this->dutyA = Duty::create(['name' => 'ภารกิจ A']);
        $this->dutyX = Duty::create(['name' => 'ภารกิจ X']);
        $this->groupB = Group::create(['duty_id' => $this->dutyA->id, 'name' => 'กลุ่มงาน B']);
        $this->groupY = Group::create(['duty_id' => $this->dutyX->id, 'name' => 'กลุ่มงาน Y']);
        $this->workC = Work::create(['group_id' => $this->groupB->id, 'name' => 'งาน C']);
        $this->workD = Work::create(['group_id' => $this->groupB->id, 'name' => 'งาน D']);
        $this->workZ = Work::create(['group_id' => $this->groupY->id, 'name' => 'งาน Z']);
    }

    // ========== Helpers ==========

    protected function employee(string $pid, string $first, string $last, ?Work $work = null): Employee
    {
        return Employee::create([
            'citizen_id'  => str_pad($pid, 13, '0', STR_PAD_LEFT),
            'employee_id' => $pid,
            'first_name'  => $first,
            'last_name'   => $last,
            'duty_id'     => $work?->group?->duty_id,
            'group_id'    => $work?->group_id,
            'work_id'     => $work?->id,
        ]);
    }

    /**
     * สร้างใบเบิกตรง ๆ ผ่าน model (ไม่ผ่าน API เพื่อโฟกัสที่การคำนวณสรุป)
     */
    protected function makeClaim(array $claimOverrides, array $items): TravelExpenseClaim
    {
        $claim = TravelExpenseClaim::create(array_merge([
            'document_no'       => 'TEC-2569-'.str_pad((string) (TravelExpenseClaim::count() + 1), 4, '0', STR_PAD_LEFT),
            'fiscal_year'       => 2569,
            'claim_period'      => '2026-06-01',
            'expense_category'  => TravelExpenseClaim::DEFAULT_CATEGORY,
            'status'            => TravelExpenseClaim::STATUS_CONFIRMED,
        ], $claimOverrides));

        foreach ($items as $index => $item) {
            $claim->items()->create(array_merge([
                'sort_order' => $index + 1,
            ], $item));
        }

        return $claim;
    }

    protected function item(Employee $employee, float $total): array
    {
        return [
            'employee_id'           => $employee->id,
            'pid'                   => $employee->employee_id,
            'first_name'            => $employee->first_name,
            'last_name'             => $employee->last_name,
            'allowance_amount'      => $total,
            'accommodation_amount'  => 0,
            'transportation_amount' => 0,
            'other_amount'          => 0,
            'total_amount'          => $total,
        ];
    }

    protected function getSummary(array $params = []): array
    {
        $response = $this->actingAs($this->finance)
            ->getJson('/api/finance/travel-expense-claims/summary?'.http_build_query($params));

        $response->assertOk();

        return $response->json('data');
    }

    protected function bucketByName(array $data, string $name): array
    {
        $buckets = collect($data['buckets'])->firstWhere('name', $name);

        $this->assertNotNull($buckets, "ไม่พบหมวด {$name} ในผลลัพธ์");

        return $buckets;
    }

    // ========== Setup data used by several tests ==========

    /**
     * ใบ 1 (มิ.ย.): สมชาย 30,000 (งาน C) + สมชายคนละรหัส 20,000 (งาน D)
     * ใบ 2 (ก.ค.): สมชาย 10,000 + สมหญิง 25,000 (ทั้งคู่งาน C)
     * ใบ 3 (ก.ค. ร่าง): สมชาย 99,999 — ไม่นับตามค่าเริ่มต้น
     * ใบ 4 (ก.ค. ยกเลิก): สมชาย 88,888 — ไม่นับเสมอ
     */
    protected function seedMainDataset(): array
    {
        // ชื่อซ้ำ คนละรหัส — ต้องไม่ถูกรวมเป็นคนเดียว
        $somchai1 = $this->employee('P001', 'สมชาย', 'ใจดี', $this->workC);
        $somchai2 = $this->employee('P002', 'สมชาย', 'ใจดี', $this->workD);
        $somying = $this->employee('P003', 'สมหญิง', 'รักดี', $this->workC);

        $this->makeClaim([], [
            $this->item($somchai1, 30000),
            $this->item($somchai2, 20000),
        ]);

        $this->makeClaim(['claim_period' => '2026-07-01'], [
            $this->item($somchai1, 10000),
            $this->item($somying, 25000),
        ]);

        $this->makeClaim(
            ['claim_period' => '2026-07-01', 'status' => TravelExpenseClaim::STATUS_DRAFT],
            [$this->item($somchai1, 99999)]
        );

        $this->makeClaim(
            ['claim_period' => '2026-07-01', 'status' => TravelExpenseClaim::STATUS_CANCELLED],
            [$this->item($somchai1, 88888)]
        );

        return [$somchai1, $somchai2, $somying];
    }

    // ========== Tests ==========

    public function test_summary_at_duty_level_allocates_money_by_work_not_by_claim(): void
    {
        $this->seedMainDataset();

        $data = $this->getSummary(['fiscal_year' => 2569, 'level' => 'duty']);

        // ใบ 1 คร่อมงาน C และ D — ยอดถูกจัดสรรตามรายการ ไม่นับทั้งใบซ้ำทุกงาน
        // ภารกิจ A = 30000 + 20000 + 10000 + 25000
        $bucketA = $this->bucketByName($data, 'ภารกิจ A');
        $this->assertEqualsWithDelta(85000.0, $bucketA['total_amount'], 0.001);
        // ใบ 1 และ ใบ 2 = 2 ใบ (ร่าง/ยกเลิกไม่นับ)
        $this->assertSame(2, $bucketA['claim_count']);
        $this->assertSame(3, $bucketA['claimant_count']);
    }

    public function test_drilldown_to_group_recomputes_from_all_works(): void
    {
        $this->seedMainDataset();

        // ระดับกลุ่มงาน (ดูภารกิจ A) — คำนวณใหม่จากทุกงานในภารกิจ A
        $groupData = $this->getSummary([
            'fiscal_year' => 2569,
            'level'       => 'group',
            'duty_id'     => $this->dutyA->id,
        ]);

        $this->assertEqualsWithDelta(85000.0, $groupData['totals']['total_amount'], 0.001);
        $this->assertSame(2, $groupData['totals']['claim_count']);
        $this->assertSame(3, $groupData['totals']['claimant_count']);

        // แถวระดับกลุ่มงาน = กลุ่มงาน B (ยอดจากทุกงานในกลุ่ม)
        $bucketB = $this->bucketByName($groupData, 'กลุ่มงาน B');
        $this->assertEqualsWithDelta(85000.0, $bucketB['total_amount'], 0.001);
        $this->assertSame(2, $bucketB['claim_count']);
        $this->assertSame(3, $bucketB['claimant_count']);

        // ระดับงาน (ดูกลุ่มงาน B) — ต้องรวมทุกงานภายในกลุ่ม ไม่ใช่ผลของงานเดียว
        $workData = $this->getSummary([
            'fiscal_year' => 2569,
            'level'       => 'work',
            'duty_id'     => $this->dutyA->id,
            'group_id'    => $this->groupB->id,
        ]);

        $workC = $this->bucketByName($workData, 'งาน C');
        $workD = $this->bucketByName($workData, 'งาน D');
        $this->assertEqualsWithDelta(65000.0, $workC['total_amount'], 0.001); // 30000 + 10000 + 25000
        $this->assertSame(2, $workC['claim_count']);
        $this->assertSame(2, $workC['claimant_count']);
        $this->assertEqualsWithDelta(20000.0, $workD['total_amount'], 0.001);
        $this->assertSame(1, $workD['claim_count']);
    }

    public function test_top_spender_is_highest_amount_not_most_claims(): void
    {
        [$somchai1, $somchai2, $somying] = $this->seedMainDataset();

        $data = $this->getSummary(['fiscal_year' => 2569, 'level' => 'duty']);

        $bucketA = $this->bucketByName($data, 'ภารกิจ A');

        // สมชาย (P001) ยอดรวม 40,000 สูงสุด แม้สมหญิงจะมีจำนวนใบเท่ากัน (แต่ยอด 25,000)
        $this->assertCount(1, $bucketA['top_spenders']);
        $this->assertSame('P001', $bucketA['top_spenders'][0]['pid']);
        $this->assertEqualsWithDelta(40000.0, $bucketA['top_spenders'][0]['total_amount'], 0.001);
        $this->assertSame(2, $bucketA['top_spenders'][0]['claim_count']);
        // สัดส่วนเทียบยอดรวมภารกิจ A: 40000 / 85000
        $this->assertEqualsWithDelta(47.06, $bucketA['top_spenders'][0]['share'], 0.001);
    }

    public function test_same_name_different_pid_is_not_merged(): void
    {
        $this->seedMainDataset();

        $data = $this->getSummary(['fiscal_year' => 2569, 'level' => 'duty']);

        $ranking = collect($data['ranking']);
        $somchaiRows = $ranking->filter(fn ($row) => $row['name'] === 'สมชาย ใจดี');

        // ชื่อซ้ำ 2 คนต้องแยกกันตามรหัส — ไม่รวมเป็น 70,000
        $this->assertCount(2, $somchaiRows);
        $this->assertEqualsCanonicalizing(
            [40000.0, 20000.0],
            $somchaiRows->pluck('total_amount')->map(fn ($v) => (float) $v)->all()
        );

        // จัดอันดับจากมากไปน้อย
        $amounts = collect($data['ranking'])->pluck('total_amount')->map(fn ($v) => (float) $v)->all();
        $this->assertSame($amounts, collect($amounts)->sortDesc()->values()->all());
        $this->assertSame(1, $data['ranking'][0]['rank']);
        $this->assertEqualsWithDelta(40000.0, $data['ranking'][0]['total_amount'], 0.001);
    }

    public function test_tied_top_spenders_are_all_shown(): void
    {
        $somchai = $this->employee('P101', 'สมชาย', 'ใจดี', $this->workZ);
        $manee = $this->employee('P102', 'มานี', 'แข็งแรง', $this->workZ);

        $this->makeClaim([], [
            $this->item($somchai, 5000),
            $this->item($manee, 5000),
        ]);

        $data = $this->getSummary([
            'fiscal_year' => 2569,
            'level'       => 'work',
            'duty_id'     => $this->dutyX->id,
            'group_id'    => $this->groupY->id,
        ]);

        $this->assertEqualsWithDelta(10000.0, $data['totals']['total_amount'], 0.001);
        $this->assertSame(1, $data['totals']['claim_count']);
        $this->assertSame(2, $data['totals']['claimant_count']);

        // ยอดสูงสุดเสมอกัน → แสดงทุกคนที่ได้อันดับสูงสุดร่วมกัน
        $top = collect($data['ranking'])->filter(fn ($row) => (float) $row['total_amount'] === 5000.0);
        $this->assertCount(2, $top);
        $this->assertEqualsCanonicalizing(['P101', 'P102'], collect($data['ranking'])->pluck('pid')->all());
        $this->assertEqualsWithDelta(50.0, $data['ranking'][0]['share'], 0.001);
    }

    public function test_draft_excluded_by_default_and_cancelled_never_counted(): void
    {
        $this->seedMainDataset();

        // ค่าเริ่มต้น: เฉพาะยืนยันแล้ว
        $data = $this->getSummary(['fiscal_year' => 2569, 'level' => 'duty']);
        $this->assertEqualsWithDelta(85000.0, $data['totals']['total_amount'], 0.001);

        // เปิดรวมใบร่าง
        $dataWithDraft = $this->getSummary([
            'fiscal_year'   => 2569,
            'level'         => 'duty',
            'include_draft' => true,
        ]);
        $this->assertEqualsWithDelta(85000.0 + 99999.0, $dataWithDraft['totals']['total_amount'], 0.001);

        // ยกเลิกไม่นับในทุกกรณี
        collect([$data, $dataWithDraft])->each(function ($d) {
            $this->assertStringNotContainsString('88888', (string) json_encode($d));
        });
    }

    public function test_period_filter_limits_months(): void
    {
        $this->seedMainDataset();

        $data = $this->getSummary([
            'fiscal_year' => 2569,
            'level'       => 'duty',
            'period_from' => '2026-07-01',
            'period_to'   => '2026-07-01',
        ]);

        // เฉพาะใบ 2 (ก.ค.): 10000 + 25000 — ร่าง/ยกเลิกของ ก.ค. ไม่นับ
        $this->assertEqualsWithDelta(35000.0, $data['totals']['total_amount'], 0.001);
        $this->assertSame(1, $data['totals']['claim_count']);
    }

    public function test_unassigned_employees_fall_into_unassigned_bucket(): void
    {
        $orphan = $this->employee('P999', 'ไร้', 'สังกัด', null);

        $this->makeClaim([], [$this->item($orphan, 12345)]);

        $data = $this->getSummary(['fiscal_year' => 2569, 'level' => 'duty']);

        $unassigned = $this->bucketByName($data, 'ไม่ระบุสังกัด');
        $this->assertNull($unassigned['id']);
        $this->assertEqualsWithDelta(12345.0, $unassigned['total_amount'], 0.001);
        $this->assertSame(1, $unassigned['claim_count']);
        $this->assertSame(1, $unassigned['claimant_count']);
        $this->assertSame('P999', $unassigned['top_spenders'][0]['pid']);
    }

    public function test_work_level_requires_group_id(): void
    {
        $this->actingAs($this->finance)
            ->getJson('/api/finance/travel-expense-claims/summary?level=work')
            ->assertStatus(422);
    }

    public function test_group_level_requires_duty_id(): void
    {
        $this->actingAs($this->finance)
            ->getJson('/api/finance/travel-expense-claims/summary?level=group')
            ->assertStatus(422);
    }

    public function test_no_data_returns_empty_result(): void
    {
        $this->seedMainDataset();

        $data = $this->getSummary(['fiscal_year' => 2568, 'level' => 'duty']);

        $this->assertEqualsWithDelta(0.0, $data['totals']['total_amount'], 0.001);
        $this->assertSame(0, $data['totals']['claim_count']);
        $this->assertSame([], $data['buckets']);
        $this->assertSame([], $data['ranking']);
    }

    public function test_hr_role_can_view_summary(): void
    {
        // หน้าย้ายไปเมนูฝั่ง HR — hr ต้องเรียกดูได้ (หน้าอื่นของใบเบิกยังจำกัด finance)
        $hr = User::create([
            'name'     => 'HR Staff',
            'username' => 'hr_summary',
            'email'    => 'hr_summary@example.test',
            'password' => bcrypt('secret-password'),
            'role'     => 'hr',
        ]);

        $this->actingAs($hr)
            ->getJson('/api/finance/travel-expense-claims/summary')
            ->assertOk();
    }

    public function test_summary_requires_authentication(): void
    {
        $this->getJson('/api/finance/travel-expense-claims/summary')
            ->assertUnauthorized();
    }
}
