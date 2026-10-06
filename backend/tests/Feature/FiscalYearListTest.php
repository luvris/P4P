<?php

namespace Tests\Feature;

use App\Models\Payroll;
use App\Models\User;
use App\Support\ThaiFiscalYear;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ตัวเลือกปีงบประมาณบน Header (/api/fiscal-years)
 *
 * ปีงบที่มีข้อมูลเงินเดือนต้องเลือกได้เสมอ แม้ยังไม่เคยบันทึกผลการคำนวณเงินสำรอง
 * ไม่งั้นหลังล้างผลการคำนวณ ผู้ใช้จะกลับไปดูปีที่มี payroll อยู่ไม่ได้เลย
 */
class FiscalYearListTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'name'     => 'ปีงบ tester',
            'username' => 'fiscal_tester',
            'email'    => 'fiscal_tester@example.test',
            'password' => bcrypt('secret-password'),
            'role'     => 'finance',
        ]);
    }

    protected function makePayroll(array $attributes = []): Payroll
    {
        return Payroll::create([
            'seq_number'           => 1,
            'period_year'          => 2569,
            'period_month'         => 1,
            'fiscal_year'          => 2569,
            'employee_type'        => 'ข้าราชการ',
            'prefix'               => 'นาย',
            'first_name'           => 'สมชาย',
            'last_name'            => 'ทดสอบ',
            'position_name'        => 'พยาบาลวิชาชีพ',
            'position_number'      => '0001',
            'citizen_id'           => '1111111111111',
            'salary'               => 30000,
            'total_direct_income'  => 30000,
            'total_income'         => 30000,
            ...$attributes,
        ]);
    }

    public function test_the_current_fiscal_year_is_always_selectable(): void
    {
        $this->actingAs($this->user)
            ->getJson('/api/fiscal-years')
            ->assertOk()
            ->assertJsonPath('data', [ThaiFiscalYear::current()]);
    }

    public function test_a_fiscal_year_with_payroll_data_is_listed(): void
    {
        // ม.ค. 2569 = ปีงบ 2569
        $this->makePayroll();

        $response = $this->actingAs($this->user)->getJson('/api/fiscal-years')->assertOk();

        $this->assertContains(2569, $response->json('data'));
    }

    public function test_an_october_period_belongs_to_the_next_fiscal_year(): void
    {
        // ต.ค. 2568 = งวดแรกของปีงบ 2569 ไม่ใช่ 2568
        $this->makePayroll(['period_year' => 2568, 'period_month' => 10, 'fiscal_year' => 2569]);

        $response = $this->actingAs($this->user)->getJson('/api/fiscal-years')->assertOk();
        $years = $response->json('data');

        $this->assertContains(2569, $years);
        $this->assertNotContains(2568, $years);
    }

    public function test_a_file_that_only_carries_the_fiscal_year_still_counts(): void
    {
        // ไฟล์รูปแบบเดิมระบุปีงบที่ชุดข้อมูล ไม่มีปี/เดือนในแถว
        $this->makePayroll([
            'period_year'  => null,
            'period_month' => null,
            'fiscal_year'  => 2567,
        ]);

        $response = $this->actingAs($this->user)->getJson('/api/fiscal-years')->assertOk();

        $this->assertContains(2567, $response->json('data'));
    }

    public function test_years_are_listed_newest_first_without_duplicates(): void
    {
        $this->makePayroll(['period_year' => 2569, 'period_month' => 1, 'fiscal_year' => 2569]);
        $this->makePayroll([
            'seq_number'          => 2,
            'period_year'         => 2568,
            'period_month'        => 5,
            'fiscal_year'         => 2568,
            'citizen_id'          => '2222222222222',
        ]);
        // แถวซ้ำปีงบเดิม ต้องไม่ทำให้ตัวเลือกซ้ำ
        $this->makePayroll([
            'seq_number'          => 3,
            'period_year'         => 2569,
            'period_month'        => 2,
            'fiscal_year'         => 2569,
            'citizen_id'          => '3333333333333',
        ]);

        $years = $this->actingAs($this->user)->getJson('/api/fiscal-years')->assertOk()->json('data');

        $sorted = $years;
        rsort($sorted);

        $this->assertSame($sorted, $years, 'ต้องเรียงปีใหม่สุดก่อน');
        $this->assertSame(count(array_unique($years)), count($years), 'ต้องไม่มีปีซ้ำ');
        $this->assertSame([2569, 2568], array_values(array_intersect($years, [2568, 2569])));
    }

    public function test_a_guest_cannot_read_the_fiscal_year_list(): void
    {
        $this->getJson('/api/fiscal-years')->assertUnauthorized();
    }
}