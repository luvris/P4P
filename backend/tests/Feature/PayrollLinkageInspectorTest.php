<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeStatus;
use App\Models\Prefix;
use App\Services\PayrollLinkageInspector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ตรวจการผูกแถว payroll กับทะเบียนบุคลากร
 *
 * เป้าหมาย: จับเลขบัตรประชาชนที่พิมพ์ผิด ก่อนที่คนนั้นจะหายไปจากเงินสำรอง
 */
class PayrollLinkageInspectorTest extends TestCase
{
    use RefreshDatabase;

    private PayrollLinkageInspector $inspector;

    protected function setUp(): void
    {
        parent::setUp();

        $this->inspector = app(PayrollLinkageInspector::class);

        $working = EmployeeStatus::create(['name' => 'ปฏิบัติงานอยู่', 'sort_order' => 1]);
        $resigned = EmployeeStatus::create(['name' => 'ลาออก', 'sort_order' => 3]);

        $missPrefix = Prefix::create(['name' => 'นางสาว', 'short_name' => 'น.ส.']);

        Employee::create([
            'citizen_id' => '1111111111111',
            'prefix_id'  => $missPrefix->id,
            'first_name' => 'ทดสอบ',
            'last_name'  => 'ทดสอบ',
            'status_id'  => $working->id,
        ]);

        Employee::create([
            'citizen_id' => '1111111111111',
            'first_name' => 'สมชาย',
            'last_name'  => 'ใจดี',
            'status_id'  => $resigned->id,
        ]);
    }

    /** แถว payroll — ไฟล์จริงใส่คำนำหน้ามาพร้อมชื่อ */
    private function row(string $citizenId, string $firstName, string $lastName): array
    {
        return ['citizen_id' => $citizenId, 'first_name' => $firstName, 'last_name' => $lastName];
    }

    public function test_a_row_that_links_correctly_produces_no_warning(): void
    {
        $warnings = $this->inspector->inspect([
            $this->row('1111111111111', 'นางสาวทดสอบ', 'ทดสอบ'),
        ]);

        $this->assertSame([], $warnings);
    }

    public function test_a_typo_in_the_citizen_id_is_flagged_as_a_name_match(): void
    {
        // เลขบัตรผิด 1 หลัก แต่ชื่อตรงกับคนที่ปฏิบัติงานอยู่
        $warnings = $this->inspector->inspect([
            $this->row('1111111111111', 'นางสาวทดสอบ', 'ทดสอบ'),
        ]);

        $this->assertCount(1, $warnings);
        $this->assertSame('link', $warnings[0]['type']);
        $this->assertSame('name_match', $warnings[0]['reason']);
        $this->assertSame(2, $warnings[0]['row']);
        $this->assertSame('1111111111111', $warnings[0]['employee_citizen_id']);
        $this->assertSame('1111111111111', $warnings[0]['file_citizen_id']);
        $this->assertStringContainsString('ทดสอบ ทดสอบ', $warnings[0]['error']);
        $this->assertStringContainsString('ปฏิบัติงานอยู่', $warnings[0]['error']);
    }

    public function test_a_name_that_only_matches_a_resigned_employee_is_not_flagged(): void
    {
        // ชื่อตรงกับคนที่ลาออกไปแล้ว (เลขบัตรผิด) — ไม่ต้องเตือน เพราะเขาออกจากงานแล้ว
        $warnings = $this->inspector->inspect([
            $this->row('1111111111111', 'นายสมชาย', 'ใจดี'),
        ]);

        $this->assertSame([], $warnings);
    }

    public function test_a_typo_that_matches_nobody_by_name_is_still_flagged_by_near_citizen_id(): void
    {
        $warnings = $this->inspector->inspect([
            $this->row('1111111111111', 'ไม่ทราบชื่อ', 'ไม่ทราบสกุล'),
        ]);

        $this->assertCount(1, $warnings);
        $this->assertSame('near_citizen_id', $warnings[0]['reason']);
        $this->assertSame('1111111111111', $warnings[0]['employee_citizen_id']);
    }

    public function test_a_completely_unknown_citizen_id_is_not_flagged(): void
    {
        $warnings = $this->inspector->inspect([
            $this->row('1111111111111', 'ไม่ทราบชื่อ', 'ไม่ทราบสกุล'),
        ]);

        $this->assertSame([], $warnings);
    }

    public function test_duplicate_rows_produce_only_one_warning(): void
    {
        $row = $this->row('1111111111111', 'นางสาวทดสอบ', 'ทดสอบ');

        $warnings = $this->inspector->inspect([$row, $row]);

        $this->assertCount(1, $warnings);
    }
}
