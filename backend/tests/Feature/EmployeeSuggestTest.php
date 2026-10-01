<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ค้นหาบุคลากรแบบพิมพ์แล้วเด้ง (autocomplete) + แนะนำเลขบัตรประชาชนที่ใกล้เคียง
 */
class EmployeeSuggestTest extends TestCase
{
    use RefreshDatabase;

    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hr = User::create([
            'name'     => 'HR Staff',
            'username' => 'hr_suggest',
            'email'    => 'hr_suggest@example.test',
            'password' => bcrypt('secret-password'),
            'role'     => 'hr',
        ]);

        $working = EmployeeStatus::create(['name' => 'ปฏิบัติงานอยู่', 'sort_order' => 1]);
        $resigned = EmployeeStatus::create(['name' => 'ลาออก', 'sort_order' => 3]);

        Employee::create([
            'citizen_id'  => '1111111111111',
            'employee_id' => 'P001',
            'first_name'  => 'สมชาย',
            'last_name'   => 'ใจดี',
            'status_id'   => $working->id,
        ]);

        Employee::create([
            'citizen_id'  => '1234567890123',
            'employee_id' => 'P002',
            'first_name'  => 'สมหญิง',
            'last_name'   => 'รักดี',
            'status_id'   => $working->id,
        ]);

        // ลาออกแล้ว — ต้องไม่ขึ้นใน autocomplete ทั้งแบบค้นตรงและแบบเลขใกล้เคียง
        Employee::create([
            'citizen_id'  => '5555555555555',
            'employee_id' => 'P003',
            'first_name'  => 'สมปอง',
            'last_name'   => 'จากไป',
            'status_id'   => $resigned->id,
        ]);
    }

    private function suggest(array $params = [])
    {
        return $this->actingAs($this->hr)
            ->getJson('/api/hr/employees/suggest?' . http_build_query($params));
    }

    public function test_typing_part_of_a_citizen_id_returns_the_matching_employee(): void
    {
        $this->suggest(['q' => '1111'])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.citizen_id', '1111111111111')
            ->assertJsonPath('data.0.near', false)
            ->assertJsonPath('meta.near', false);
    }

    public function test_it_can_search_by_name(): void
    {
        $this->suggest(['q' => 'สมหญิง'])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.first_name', 'สมหญิง');
    }

    public function test_a_typo_in_the_citizen_id_falls_back_to_the_nearest_number(): void
    {
        // พิมพ์ผิด 1 หลัก (ลงท้าย 4 แทน 3)
        $this->suggest(['q' => '1234567890124'])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.citizen_id', '1234567890123')
            ->assertJsonPath('data.0.near', true)
            ->assertJsonPath('data.0.distance', 1)
            ->assertJsonPath('meta.near', true);
    }

    public function test_a_short_number_does_not_produce_near_matches(): void
    {
        $this->suggest(['q' => '99'])
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.near', false);
    }

    public function test_a_number_with_no_close_relative_returns_nothing(): void
    {
        $this->suggest(['q' => '999999'])
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.near', false);
    }

    public function test_empty_query_returns_a_first_page_of_employees(): void
    {
        $this->suggest()
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.near', false);
    }

    public function test_resigned_employees_are_not_suggested(): void
    {
        // ค้นด้วยเลขบัตรตรง ๆ ของคนที่ลาออกแล้ว → ต้องไม่เจอ
        $this->suggest(['q' => '5555555555555'])
            ->assertOk()
            ->assertJsonCount(0, 'data');

        // ค้นด้วยชื่อของคนที่ลาออกแล้ว → ต้องไม่เจอ
        $this->suggest(['q' => 'สมปอง'])
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_resigned_employee_is_not_offered_as_a_near_match(): void
    {
        // พิมพ์เลขของคนที่ลาออกผิดไป 1 หลัก — ต้องไม่ถูกเสนอเป็น "ใกล้เคียง"
        $this->suggest(['q' => '5555555555556'])
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.near', false);
    }

    public function test_empty_query_only_returns_working_employees(): void
    {
        $this->suggest()
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_guests_cannot_use_the_suggest_endpoint(): void
    {
        $this->getJson('/api/hr/employees/suggest?q=1111')->assertUnauthorized();
    }
}
