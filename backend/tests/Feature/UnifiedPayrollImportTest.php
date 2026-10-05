<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeType;
use App\Models\Import;
use App\Models\Payroll;
use App\Models\Position;
use App\Models\Prefix;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Tests\TestCase;

/**
 * หน้าอัปโหลดไฟล์เงินเดือนแบบรวม — ไฟล์เดียวได้ทั้งทะเบียนบุคลากรและแถวเงินเดือน
 *
 * เดิมมีสองหน้า (ฝั่ง HR กับฝั่งการเงิน) ที่ต้องอัปโหลดไฟล์เดียวกันสองครั้ง
 * ตอนนี้รวมเป็น /api/imports ใช้ได้ทั้ง HR และการเงิน
 */
class UnifiedPayrollImportTest extends TestCase
{
    use RefreshDatabase;

    protected User $hr;

    protected User $finance;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hr = $this->makeUser('hr', 'unified_hr');
        $this->finance = $this->makeUser('finance', 'unified_finance');
    }

    protected function makeUser(string $role, string $username): User
    {
        return User::create([
            'name'     => $role . ' tester',
            'username' => $username,
            'email'    => $username . '@example.test',
            'password' => bcrypt('secret-password'),
            'role'     => $role,
        ]);
    }

    /** หัวตารางตามไฟล์จริง (เฉพาะคอลัมน์ที่ test ใช้) */
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

        $path = tempnam(sys_get_temp_dir(), 'unified_') . '.xlsx';
        (new XlsxWriter($spreadsheet))->save($path);

        return new UploadedFile($path, 'payroll.xlsx', null, null, true);
    }

    /** ไฟล์ 2 คน 2 งวด — คนแรกมีสองงวด คนที่สองมีหนึ่งงวด */
    protected function twoPeriodFile(): UploadedFile
    {
        return $this->makeXlsx([
            ['1', '2568', 'มิถุนายน', 'นาย', 'สมชาย', 'ใจดี', 'ข้าราชการ', 'พยาบาล', '101', '1111111111111', '012-345-678', '30,000', '30000', '600', '30600'],
            ['2', '2569', 'มกราคม', 'นาย', 'สมชาย', 'ใจดี', 'ข้าราชการ', 'พยาบาล', '101', '1111111111111', '012-345-678', '32,000', '32000', '640', '32640'],
            ['3', '2569', 'มีนาคม', 'นาง', 'สมหญิง', 'รักดี', 'ลูกจ้าง', 'พยาบาล', '205', '2222222222222', '111-222-333', '25,000', '25000', '500', '25500'],
        ]);
    }

    public function test_one_upload_writes_both_the_registry_and_the_payroll_rows(): void
    {
        Prefix::create(['name' => 'นาย']);
        EmployeeType::create(['name' => 'ข้าราชการ']);

        $response = $this->actingAs($this->hr)
            ->post('/api/imports', ['file' => $this->twoPeriodFile()]);

        $response->assertCreated();

        // ฝั่งทะเบียนบุคลากร: ยุบเหลืองวดล่าสุดต่อคน = 2 คน
        $response->assertJsonPath('employee.inserted', 2)
            ->assertJsonPath('employee.updated', 0)
            ->assertJsonPath('employee.total', 2);

        // ฝั่งเงินเดือน: ทุกแถวในไฟล์ = 3 คน-งวด
        $response->assertJsonPath('payroll.success_rows', 3);

        $this->assertSame(2, Employee::count());
        $this->assertSame(3, Payroll::count());

        // คนแรกต้องได้เงินเดือนของงวดมกราคม ไม่ใช่มิถุนายน
        $employee = Employee::where('citizen_id', '1111111111111')->firstOrFail();
        $this->assertSame(32000.00, (float) $employee->latest_salary);
        $this->assertSame(1, $employee->latest_period_month);
        $this->assertSame('พยาบาล', Position::findOrFail($employee->position_id)->name);
    }

    public function test_both_roles_can_use_the_same_endpoint(): void
    {
        // หน้าเดียว ใช้ร่วมกัน — ทั้ง HR และการเงินต้องอัปโหลดได้
        foreach ([$this->hr, $this->finance] as $user) {
            $response = $this->actingAs($user)
                ->post('/api/imports', ['file' => $this->twoPeriodFile()]);

            $response->assertCreated()
                ->assertJsonPath('employee.total', 2)
                ->assertJsonPath('payroll.success_rows', 3);
        }

        // อัปสองครั้ง (คนละ user) ต้องไม่สร้างพนักงานซ้ำ
        $this->assertSame(2, Employee::count());
    }

    public function test_the_registry_is_written_before_the_payroll_rows(): void
    {
        // payrolls ต้องเดาเลขบัตรจากทะเบียน — ถ้าทะเบียนยังว่าง
        // ทุกแถวจะกลายเป็นแถวที่ผูกไม่ได้และหลุดจากฐานเงินสำรอง
        $this->actingAs($this->hr)
            ->post('/api/imports', ['file' => $this->twoPeriodFile()])
            ->assertCreated()
            ->assertJsonPath('unlinked_summary', null);

        $this->assertSame(0, Payroll::whereNull('citizen_id')->orWhere('citizen_id', '')->count());
    }

    public function test_preview_reports_both_sides_without_writing(): void
    {
        $response = $this->actingAs($this->finance)
            ->post('/api/imports/preview', ['file' => $this->twoPeriodFile()]);

        $response->assertOk()
            ->assertJsonPath('payroll.total_rows', 3)
            ->assertJsonPath('employee.preview.total_rows', 2)
            ->assertJsonPath('employee.preview.new_count', 2)
            ->assertJsonPath('employee.preview.update_count', 0);

        // preview ต้องไม่เขียนอะไรลงฐานข้อมูล
        $this->assertSame(0, Employee::count());
        $this->assertSame(0, Payroll::count());
    }

    public function test_preview_marks_existing_citizen_id_as_update(): void
    {
        Employee::create([
            'citizen_id' => '1111111111111',
            'first_name' => 'เดิม',
            'last_name'  => 'เดิม',
        ]);

        $this->actingAs($this->hr)
            ->post('/api/imports/preview', ['file' => $this->twoPeriodFile()])
            ->assertOk()
            ->assertJsonPath('employee.preview.new_count', 1)
            ->assertJsonPath('employee.preview.update_count', 1)
            ->assertJsonPath('employee.preview.rows.0.action', 'update');
    }

    public function test_reupload_updates_the_registry_instead_of_duplicating(): void
    {
        $this->actingAs($this->hr)
            ->post('/api/imports', ['file' => $this->twoPeriodFile()])
            ->assertCreated();

        $response = $this->actingAs($this->hr)
            ->post('/api/imports', ['file' => $this->twoPeriodFile()]);

        $response->assertCreated()
            ->assertJsonPath('employee.inserted', 0)
            ->assertJsonPath('employee.updated', 2);

        $this->assertSame(2, Employee::count());
    }

    public function test_file_without_new_format_columns_is_rejected(): void
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getActiveSheet()->fromArray([
            ['PID', 'SERIAL', 'PREFIX', 'FIRST', 'LAST'],
            ['1', '2', 'นาย', 'สมชาย', 'ใจดี'],
        ], null, 'A1');

        $path = tempnam(sys_get_temp_dir(), 'unified_old_') . '.xlsx';
        (new XlsxWriter($spreadsheet))->save($path);
        $file = new UploadedFile($path, 'old_format.xlsx', null, null, true);

        $message = 'ไฟล์นี้ไม่ใช่ไฟล์เงินเดือนรูปแบบใหม่ — ต้องมีคอลัมน์ ลำดับที่ / ปี / เดือน ครบ';

        $this->actingAs($this->hr)
            ->post('/api/imports/preview', ['file' => $file])
            ->assertStatus(422)
            ->assertJsonPath('message', $message);

        $this->actingAs($this->hr)
            ->post('/api/imports', ['file' => $file])
            ->assertStatus(422)
            ->assertJsonPath('message', $message);

        $this->assertSame(0, Import::count());
    }

    public function test_rows_without_citizen_id_are_reported_as_a_warning(): void
    {
        $file = $this->makeXlsx([
            ['1', '2569', 'มกราคม', 'นาย', 'ไม่มีบัตร', 'ทดสอบ', 'ข้าราชการ', 'พยาบาล', '101', '', '012-345-678', '32000', '32000', '640', '32640'],
            ['2', '2569', 'มกราคม', 'นาย', 'มีบัตร', 'ทดสอบ', 'ข้าราชการ', 'พยาบาล', '102', '3333333333333', '012-345-678', '32000', '32000', '640', '32640'],
        ]);

        $response = $this->actingAs($this->hr)
            ->post('/api/imports/preview', ['file' => $file]);

        $response->assertOk()
            ->assertJsonPath('employee.preview.total_rows', 1)
            ->assertJsonPath('warning_count', 1);

        $this->assertStringContainsString('ไม่พบเลขบัตรประชาชน', $response->json('warnings.0'));
    }

    public function test_both_sides_record_the_same_stored_file(): void
    {
        $this->actingAs($this->hr)
            ->post('/api/imports', ['file' => $this->twoPeriodFile()])
            ->assertCreated();

        // ทั้งสองฝั่งต้องชี้ไฟล์เดียวกัน มิฉะนั้นการค้นย้อนไฟล์ที่นำเข้า
        // จะเจอแค่ฝั่งเดียวและไฟล์ค้างใน storage จะไม่มีใครชี้
        $paths = DB::table('imports')->pluck('file_path')->unique();

        $this->assertCount(1, $paths);
        $this->assertStringStartsWith('imports/', $paths->first());
    }

    public function test_the_import_history_is_shared_across_roles(): void
    {
        $this->actingAs($this->hr)
            ->post('/api/imports', ['file' => $this->twoPeriodFile()])
            ->assertCreated();

        // ประวัติการนำเข้าอยู่ที่เดียว ทั้งสองฝั่งดูเห็นเหมือนกัน
        $this->actingAs($this->finance)
            ->getJson('/api/imports')
            ->assertOk()
            ->assertJsonPath('total', 1);

        $this->actingAs($this->hr)
            ->getJson('/api/imports')
            ->assertOk()
            ->assertJsonPath('total', 1);
    }

    public function test_a_guest_cannot_upload(): void
    {
        $this->postJson('/api/imports', [])
            ->assertUnauthorized();
    }
}