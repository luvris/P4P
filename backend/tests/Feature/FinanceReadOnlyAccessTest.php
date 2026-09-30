<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * finance ดูหน้า Dashboard (รายชื่อบุคลากร) ได้แบบ read-only
 * อ่านได้ แต่เพิ่ม/แก้ไขไม่ได้
 */
class FinanceReadOnlyAccessTest extends TestCase
{
    use RefreshDatabase;

    protected User $finance;
    protected User $hr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->finance = User::create([
            'name'     => 'Finance Staff',
            'username' => 'finance_ro',
            'email'    => 'finance_ro@example.test',
            'password' => bcrypt('secret-password'),
            'role'     => 'finance',
        ]);

        $this->hr = User::create([
            'name'     => 'HR Staff',
            'username' => 'hr_ro',
            'email'    => 'hr_ro@example.test',
            'password' => bcrypt('secret-password'),
            'role'     => 'hr',
        ]);

        Employee::create([
            'citizen_id' => '1111111111111',
            'first_name' => 'สมชาย',
            'last_name'  => 'ใจดี',
        ]);
    }

    public function test_finance_can_read_employee_list(): void
    {
        $this->actingAs($this->finance)
            ->getJson('/api/hr/employees')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_finance_can_read_stats_and_lookups(): void
    {
        $this->actingAs($this->finance)
            ->getJson('/api/hr/employees/stats')
            ->assertOk();

        $this->actingAs($this->finance)
            ->getJson('/api/hr/lookups')
            ->assertOk();
    }

    public function test_finance_cannot_create_employee(): void
    {
        $this->actingAs($this->finance)
            ->postJson('/api/hr/employees', [
                'citizen_id' => '2222222222222',
                'first_name' => 'สมหญิง',
                'last_name'  => 'รักดี',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('employees', ['citizen_id' => '2222222222222']);
    }

    public function test_finance_cannot_update_employee(): void
    {
        $employee = Employee::first();

        $this->actingAs($this->finance)
            ->putJson("/api/hr/employees/{$employee->id}", ['first_name' => 'เปลี่ยนชื่อ'])
            ->assertForbidden();

        $this->assertSame('สมชาย', $employee->fresh()->first_name);
    }

    public function test_finance_cannot_write_reserve_fund_calculations(): void
    {
        $this->actingAs($this->finance)
            ->postJson('/api/hr/reserve-fund/calculations', [
                'percent'      => 3,
                'period_month' => 10,
            ])
            ->assertForbidden();
    }

    public function test_hr_write_access_is_unchanged(): void
    {
        // ผ่าน middleware role ได้ (ตกที่ validation ไม่ใช่ 403)
        $this->actingAs($this->hr)
            ->postJson('/api/hr/employees', [
                'citizen_id' => '3333333333333',
                'first_name' => 'สมปอง',
                'last_name'  => 'ดีใจ',
            ])
            ->assertStatus(422);

        $this->actingAs($this->hr)
            ->getJson('/api/hr/employees')
            ->assertOk();
    }
}
