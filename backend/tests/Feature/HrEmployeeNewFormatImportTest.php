<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeType;
use App\Models\Import;
use App\Models\Position;
use App\Models\Prefix;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Tests\TestCase;

/**
 * นำเข้าทะเบียนบุคลากรจากไฟล์เงินเดือนรูปแบบใหม่ (39 คอลัมน์)
 */
class HrEmployeeNewFormatImportTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'name'     => 'HR Tester',
            'username' => 'hr_new_format',
            'email'    => 'hr_new_format@example.test',
            'password' => bcrypt('secret-password'),
            'role'     => 'hr',
        ]);
    }

    /**
     * หัวตารางตามไฟล์จริง (เฉพาะคอลัมน์ที่ test ใช้)
     */
    protected const HEADER = [
        'ลำดับที่', 'ปี', 'เดือน', 'คำนำหน้า', 'ชื่อ', 'นามสกุล', 'ประเภท',
        'ตำแหน่ง', 'ตำแหน่งเลขที่', 'id card', 'เลขที่บัญชี', 'เงินเดือน',
        'รวมรายรับทางตรง', 'รวมรายรับทางอ้อม', 'ยอดรวมรายรับทั้งหมด รายบุคคล',
    ];

    protected function makeXlsx(array $rows): UploadedFile
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getActiveSheet()
            ->fromArray([self::HEADER, ...$rows], null, 'A1');

        $path = tempnam(sys_get_temp_dir(), 'hr_new_') . '.xlsx';
        (new XlsxWriter($spreadsheet))->save($path);

        return new UploadedFile($path, 'payroll_new_format.xlsx', null, null, true);
    }

    public function test_preview_collapses_file_to_latest_period_per_person(): void
    {
        $file = $this->makeXlsx([
            ['1', '2568', 'มิถุนายน', 'นาย', 'สมชาย', 'ใจดี', 'ข้าราชการ', 'พยาบาล', '101', '1111111111111', '012-345-678', '30,000', '30000', '600', '30600'],
            ['2', '2569', 'มกราคม', 'นาย', 'สมชาย', 'ใจดี', 'ข้าราชการ', 'พยาบาล', '101', '1111111111111', '012-345-678', '32,000', '32000', '640', '32640'],
            ['3', '2569', 'มีนาคม', 'นาง', 'สมหญิง', 'รักดี', 'ลูกจ้าง', 'พยาบาล', '205', '2222222222222', '111-222-333', '25,000', '25000', '500', '25500'],
        ]);

        $response = $this->actingAs($this->user)
            ->post('/api/hr/imports/preview', ['file' => $file]);

        $response->assertOk()
            ->assertJsonPath('total_rows', 2)
            ->assertJsonPath('preview.total_rows', 2)
            ->assertJsonPath('preview.new_count', 2)
            ->assertJsonPath('preview.update_count', 0)
            ->assertJsonPath('warning_count', 0);

        $rows = $response->json('preview.rows');
        $this->assertCount(2, $rows);
        // คนแรกต้องเป็นงวดมกราคม 2569 (งวดล่าสุดของเขา) ไม่ใช่มิถุนายน 2568
        $this->assertSame(1, $rows[0]['period_month']);
        $this->assertSame(32000.0, (float) $rows[0]['latest_salary']);
        $this->assertSame('insert', $rows[0]['action']);

        // preview ต้องไม่เขียนฐานข้อมูล
        $this->assertSame(0, Employee::count());
    }

    public function test_preview_marks_existing_citizen_id_as_update(): void
    {
        Employee::create([
            'citizen_id' => '1111111111111',
            'first_name' => 'เดิม',
            'last_name'  => 'เดิม',
        ]);

        $file = $this->makeXlsx([
            ['1', '2569', 'มกราคม', 'นาย', 'สมชาย', 'ใจดี', 'ข้าราชการ', 'พยาบาล', '101', '1111111111111', '012-345-678', '32,000', '32000', '640', '32640'],
        ]);

        $response = $this->actingAs($this->user)
            ->post('/api/hr/imports/preview', ['file' => $file]);

        $response->assertOk()
            ->assertJsonPath('preview.new_count', 0)
            ->assertJsonPath('preview.update_count', 1)
            ->assertJsonPath('preview.rows.0.action', 'update');
    }

    public function test_store_creates_employee_with_position_and_latest_salary(): void
    {
        $prefix = Prefix::create(['name' => 'นาย']);
        $type   = EmployeeType::create(['name' => 'ข้าราชการ']);

        $file = $this->makeXlsx([
            ['7', '2569', 'มกราคม', 'นาย', 'สมชาย', 'ใจดี', 'ข้าราชการ', 'พยาบาลวิชาชีพ', '101', '1111111111111', '012-345-678', '32,000', '32000', '640', '32640'],
        ]);

        $response = $this->actingAs($this->user)
            ->post('/api/hr/imports', ['file' => $file]);

        $response->assertCreated()
            ->assertJsonPath('summary.inserted', 1)
            ->assertJsonPath('summary.updated', 0)
            ->assertJsonPath('summary.total', 1);

        $employee = Employee::where('citizen_id', '1111111111111')->firstOrFail();

        $this->assertSame('สมชาย', $employee->first_name);
        $this->assertSame('ใจดี', $employee->last_name);
        $this->assertSame($prefix->id, $employee->prefix_id);
        $this->assertSame($type->id, $employee->employee_type_id);
        $this->assertSame('012-345-678', $employee->bank_account);
        $this->assertSame('101', (string) $employee->position_number);
        $this->assertSame(32000.00, (float) $employee->latest_salary);
        $this->assertSame(2569, $employee->latest_period_year);
        $this->assertSame(1, $employee->latest_period_month);

        // ตำแหน่งใหม่ต้องถูกสร้างในทะเบียนตำแหน่งและผูกกับพนักงาน
        $this->assertNotNull($employee->position_id);
        $this->assertSame('พยาบาลวิชาชีพ', Position::findOrFail($employee->position_id)->name);

        $import = Import::latest('id')->firstOrFail();
        $this->assertSame('hr', $import->import_type);
        $this->assertSame('completed', $import->status);
    }

    public function test_store_updates_existing_employee_instead_of_duplicating(): void
    {
        Employee::create([
            'citizen_id' => '1111111111111',
            'first_name' => 'เก่า',
            'last_name'  => 'เก่า',
        ]);

        $file = $this->makeXlsx([
            ['1', '2569', 'มีนาคม', 'นาง', 'ใหม่', 'ใหม่', 'ลูกจ้าง', 'พยาบาล', '205', '1111111111111', '999-888-777', '40,000', '40000', '800', '40800'],
        ]);

        $response = $this->actingAs($this->user)
            ->post('/api/hr/imports', ['file' => $file]);

        $response->assertCreated()
            ->assertJsonPath('summary.inserted', 0)
            ->assertJsonPath('summary.updated', 1);

        $this->assertSame(1, Employee::count());

        $employee = Employee::where('citizen_id', '1111111111111')->firstOrFail();
        $this->assertSame('ใหม่', $employee->first_name);
        $this->assertSame('พยาบาล', $employee->position->name);
        $this->assertSame(3, $employee->latest_period_month);
        $this->assertSame(40000.00, (float) $employee->latest_salary);
    }

    public function test_file_without_new_format_columns_is_rejected(): void
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getActiveSheet()->fromArray([
            ['PID', 'SERIAL', 'PREFIX', 'FIRST', 'LAST'],
            ['1', '2', 'นาย', 'สมชาย', 'ใจดี'],
        ], null, 'A1');

        $path = tempnam(sys_get_temp_dir(), 'hr_old_') . '.xlsx';
        (new XlsxWriter($spreadsheet))->save($path);
        $file = new UploadedFile($path, 'old_format.xlsx', null, null, true);

        $this->actingAs($this->user)
            ->post('/api/hr/imports/preview', ['file' => $file])
            ->assertStatus(422)
            ->assertJsonPath('message', 'ไฟล์นี้ไม่ใช่ไฟล์เงินเดือนรูปแบบใหม่ — ต้องมีคอลัมน์ ลำดับที่ / ปี / เดือน ครบ');

        $this->actingAs($this->user)
            ->post('/api/hr/imports', ['file' => $this->makeXlsx([
                ['1', '2569', 'มกราคม', 'นาย', 'สมชาย', 'ใจดี', 'ข้าราชการ', 'พยาบาล', '101', '1111111111111', '012-345-678', '32000', '32000', '640', '32640'],
            ])])
            ->assertCreated();

        // หลังยืนยันสำเร็จ ต้องมีประวัตินำเข้า 1 รายการ
        $this->assertSame(1, Import::where('import_type', 'hr')->count());
    }

    public function test_rows_without_citizen_id_are_skipped_with_warning(): void
    {
        $file = $this->makeXlsx([
            ['1', '2569', 'มกราคม', 'นาย', 'ไม่มีบัตร', 'ทดสอบ', 'ข้าราชการ', 'พยาบาล', '101', '', '012-345-678', '32000', '32000', '640', '32640'],
            ['2', '2569', 'มกราคม', 'นาย', 'มีบัตร', 'ทดสอบ', 'ข้าราชการ', 'พยาบาล', '102', '3333333333333', '012-345-678', '32000', '32000', '640', '32640'],
        ]);

        $response = $this->actingAs($this->user)
            ->post('/api/hr/imports/preview', ['file' => $file]);

        $response->assertOk()
            ->assertJsonPath('preview.total_rows', 1)
            ->assertJsonPath('warning_count', 1);

        $this->assertStringContainsString('ไม่พบเลขบัตรประชาชน', $response->json('warnings.0'));
    }
}