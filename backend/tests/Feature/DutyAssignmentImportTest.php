<?php

namespace Tests\Feature;

use App\Models\Duty;
use App\Models\Employee;
use App\Models\Group;
use App\Models\Import;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Tests\TestCase;

class DutyAssignmentImportTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Duty $duty;
    protected Group $group;
    protected Work $work;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'name'     => 'HR Tester',
            'username' => 'hr_tester',
            'email'    => 'hr_tester@example.test',
            'password' => bcrypt('secret-password'),
            'role'     => 'hr',
        ]);

        $this->duty = Duty::create(['name' => 'ด้านการพยาบาล']);
        $this->group = Group::create(['name' => 'กลุ่มงานการพยาบาลผู้ป่วยนอก', 'duty_id' => $this->duty->id]);
        $this->work = Work::create(['name' => 'งานผู้ป่วยนอก', 'group_id' => $this->group->id]);
    }

    /**
     * สร้างไฟล์ xlsx ชั่วคราวสำหรับทดสอบ
     */
    protected function makeXlsx(array $rows, array $header = ['PID', 'DUTY', 'GROUP', 'WORK']): UploadedFile
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray([$header, ...$rows], null, 'A1');

        $path = tempnam(sys_get_temp_dir(), 'duty_') . '.xlsx';
        (new XlsxWriter($spreadsheet))->save($path);

        return new UploadedFile($path, 'duty.xlsx', null, null, true);
    }

    public function test_preview_returns_matched_rows_without_writing(): void
    {
        $employee = Employee::create([
            'citizen_id'  => '1111111111111',
            'employee_id' => '184',
            'first_name'  => 'สมหญิง',
            'last_name'   => 'ใจดี',
        ]);

        $file = $this->makeXlsx([
            ['184', 'ด้านการพยาบาล', 'กลุ่มงานการพยาบาลผู้ป่วยนอก', 'งานผู้ป่วยนอก'],
        ]);

        $response = $this->actingAs($this->user)
            ->post('/api/hr/duty-assignment-imports/preview', ['file' => $file]);

        $response->assertOk()
            ->assertJsonPath('total_rows', 1)
            ->assertJsonPath('preview.matched_rows', 1)
            ->assertJsonPath('warning_count', 0);

        // preview ต้องไม่เขียนฐานข้อมูล
        $this->assertNull($employee->fresh()->duty_id);
        $this->assertSame(0, Import::count());
    }

    public function test_store_updates_duty_group_and_work_by_pid(): void
    {
        $employee = Employee::create([
            'citizen_id'  => '2222222222222',
            'employee_id' => '200',
            'first_name'  => 'สมชาย',
            'last_name'   => 'รักงาน',
        ]);

        $file = $this->makeXlsx([
            ['200', 'ด้านการพยาบาล', 'กลุ่มงานการพยาบาลผู้ป่วยนอก', 'งานผู้ป่วยนอก'],
        ]);

        $response = $this->actingAs($this->user)
            ->post('/api/hr/duty-assignment-imports', ['file' => $file]);

        $response->assertCreated()
            ->assertJsonPath('summary.updated', 1)
            ->assertJsonPath('summary.skipped', 0)
            ->assertJsonPath('summary.errors', 0);

        $employee->refresh();
        $this->assertSame($this->duty->id, $employee->duty_id);
        $this->assertSame($this->group->id, $employee->group_id);
        $this->assertSame($this->work->id, $employee->work_id);

        $this->assertDatabaseHas('imports', [
            'import_type' => 'duty_assignment',
            'status'      => 'completed',
        ]);
    }

    public function test_store_skips_unknown_pid_and_unknown_duty(): void
    {
        Employee::create([
            'citizen_id'  => '3333333333333',
            'employee_id' => '300',
            'first_name'  => 'สมปอง',
            'last_name'   => 'ตั้งใจ',
        ]);

        $file = $this->makeXlsx([
            ['999', 'ด้านการพยาบาล', null, null],       // PID ไม่มีในระบบ
            ['300', 'ภารกิจที่ไม่มีจริง', null, null],   // ภารกิจไม่มีในระบบ
        ]);

        $response = $this->actingAs($this->user)
            ->post('/api/hr/duty-assignment-imports', ['file' => $file]);

        $response->assertCreated()
            ->assertJsonPath('summary.updated', 0)
            ->assertJsonPath('summary.skipped', 2);

        $this->assertNull(Employee::where('employee_id', '300')->first()->duty_id);
    }

    public function test_store_matches_thai_headers_with_code_prefixes(): void
    {
        $employee = Employee::create([
            'citizen_id'  => '4444444444444',
            'employee_id' => '470',
            'first_name'  => 'Test',
            'last_name'   => 'Test',
        ]);

        // header และค่าตามไฟล์จริง: PARTY = กลุ่มงาน, AGENCIES = งาน
        // ค่ามีรหัสนำหน้า "59_" และภารกิจมีคำว่า "ภารกิจ" นำหน้า
        $file = $this->makeXlsx(
            [['470', 'ภารกิจด้านการพยาบาล', '59_กลุ่มงานการพยาบาลผู้ป่วยนอก', '59_งานการพยาบาลผู้ป่วยนอกอายุรกรรม']],
            ['PID', 'ภารกิจ', 'PARTY', 'AGENCIES']
        );

        $outpatientWork = Work::create([
            'name'     => 'งานการพยาบาลผู้ป่วยนอกอายุรกรรม',
            'group_id' => $this->group->id,
        ]);

        $this->actingAs($this->user)
            ->post('/api/hr/duty-assignment-imports', ['file' => $file])
            ->assertCreated()
            ->assertJsonPath('summary.updated', 1)
            ->assertJsonPath('summary.skipped', 0);

        $employee->refresh();
        $this->assertSame($this->duty->id, $employee->duty_id);
        $this->assertSame($this->group->id, $employee->group_id);
        $this->assertSame($outpatientWork->id, $employee->work_id);
    }

    public function test_unmatched_group_still_imports_duty(): void
    {
        $employee = Employee::create([
            'citizen_id'  => '5555555555555',
            'employee_id' => '480',
            'first_name'  => 'สมศรี',
            'last_name'   => 'ทดสอบ',
        ]);

        $file = $this->makeXlsx(
            [['480', 'ภารกิจด้านการพยาบาล', '99_กลุ่มงานที่ไม่มีจริง', null]],
            ['PID', 'ภารกิจ', 'PARTY', 'AGENCIES']
        );

        $this->actingAs($this->user)
            ->post('/api/hr/duty-assignment-imports', ['file' => $file])
            ->assertCreated()
            ->assertJsonPath('summary.updated', 1);

        $employee->refresh();
        $this->assertSame($this->duty->id, $employee->duty_id);
        $this->assertNull($employee->group_id);
    }

    public function test_store_rejects_file_without_required_columns(): void
    {
        $file = $this->makeXlsx(
            [['184', 'x']],
            ['FOO', 'BAR']
        );

        $this->actingAs($this->user)
            ->post('/api/hr/duty-assignment-imports', ['file' => $file])
            ->assertStatus(422)
            ->assertJsonPath('missing_columns', ['PID', 'DUTY', 'GROUP', 'WORK']);
    }

    public function test_existing_hr_employee_import_route_is_untouched(): void
    {
        // ยืนยันว่า route เดิมยังอยู่และยัง validate ไฟล์เหมือนเดิม
        $this->actingAs($this->user)
            ->postJson('/api/hr/imports', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');
    }
}
