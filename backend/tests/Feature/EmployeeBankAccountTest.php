<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Payroll;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * เลขที่บัญชีที่แสดงบนหน้ารายชื่อบุคลากร
 *
 * คนที่ผูกกับข้อมูล payroll ไม่ได้ (เช่น เลขบัตรประชาชนไม่ตรง หรือยังไม่มี payroll)
 * ต้องยังแสดงเลขที่บัญชีที่บันทึกไว้บนตัวบุคลากร ไม่ใช่ "-"
 */
class EmployeeBankAccountTest extends TestCase
{
    use RefreshDatabase;

    private User $hr;

    private $import;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hr = User::create([
            'name'     => 'HR Tester',
            'username' => 'hr_bank',
            'email'    => 'hr_bank@example.test',
            'password' => bcrypt('secret-password'),
            'role'     => 'hr',
        ]);

        $this->import = \App\Models\Import::create([
            'file_name'   => 'payroll.xlsx',
            'file_path'   => 'imports/payroll.xlsx',
            'file_type'   => 'xlsx',
            'uploaded_by' => $this->hr->id,
            'status'      => 'completed',
        ]);
    }

    public function test_an_employee_without_payroll_data_falls_back_to_their_own_bank_account(): void
    {
        $employee = Employee::create([
            'citizen_id'   => '1111111111111',
            'first_name'   => 'สมชาย',
            'last_name'    => 'ใจดี',
            'bank_account' => '5210553124',
        ]);

        $this->assertSame('5210553124', $employee->fresh()->bank_account_number);

        $this->actingAs($this->hr)
            ->getJson('/api/hr/employees')
            ->assertOk()
            ->assertJsonPath('data.0.bank_account_number', '5210553124');
    }

    public function test_payroll_bank_account_is_used_when_available(): void
    {
        $employee = Employee::create([
            'citizen_id' => '1111111111111',
            'first_name' => 'สมชาย',
            'last_name'  => 'ใจดี',
        ]);

        Payroll::create([
            'import_id'    => $this->import->id,
            'citizen_id'   => '1111111111111',
            'first_name'   => 'สมชาย',
            'last_name'    => 'ใจดี',
            'bank_account' => '9999999999',
            'salary'       => 20000,
        ]);

        $this->assertSame('9999999999', $employee->fresh()->bank_account_number);
    }

    public function test_it_returns_null_when_there_is_no_bank_account_anywhere(): void
    {
        $employee = Employee::create([
            'citizen_id' => '1111111111111',
            'first_name' => 'สมชาย',
            'last_name'  => 'ใจดี',
        ]);

        $this->assertNull($employee->fresh()->bank_account_number);
    }

    public function test_a_payroll_row_with_an_empty_account_does_not_hide_the_employee_account(): void
    {
        $employee = Employee::create([
            'citizen_id'   => '1111111111111',
            'first_name'   => 'สมชาย',
            'last_name'    => 'ใจดี',
            'bank_account' => '5210553124',
        ]);

        Payroll::create([
            'import_id'    => $this->import->id,
            'citizen_id'   => '1111111111111',
            'first_name'   => 'สมชาย',
            'last_name'    => 'ใจดี',
            'bank_account' => '',
            'salary'       => 20000,
        ]);

        $this->assertSame('5210553124', $employee->fresh()->bank_account_number);
    }
}
