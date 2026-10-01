<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeStatus;
use App\Models\Import;
use App\Models\Payroll;
use App\Models\User;
use App\Services\ImportService;
use App\Services\Parsers\XlsxParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Tests\TestCase;

/**
 * นำเข้าไฟล์ payroll
 *
 * ต้องไม่นับแถวรวมยอด/แถวว่างเป็นบุคลากร (ทำให้ฐานเงินสำรองพองเป็นสองเท่า)
 * และต้องนำเข้าไฟล์เดิมซ้ำเป็น snapshot ใหม่ได้
 */
class PayrollImportTest extends TestCase
{
    use RefreshDatabase;

    /** ไฟล์ชั่วคราวที่ต้องลบหลังจบเทสต์ */
    private array $tempFiles = [];

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'name'     => 'Finance Tester',
            'username' => 'finance_payroll',
            'email'    => 'finance_payroll@example.test',
            'password' => bcrypt('secret-password'),
            'role'     => 'finance',
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        parent::tearDown();
    }

    /**
     * header ที่จับคู่ได้กับชื่อคอลัมน์จริงในไฟล์ payroll
     */
    private function header(): array
    {
        return [
            'ประเภท', 'บัตรประชาชน', 'ชื่อ', 'นามสกุล', 'เลขที่บัญชี',
            'เงินเดือน', 'ครองชีพ', 'พตส', 'ล่วงเวลา', 'P4P', 'รวมรายรับ', 'รับจริง',
        ];
    }

    private function writePayrollFile(array $rows, ?array $header = null): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray($header ?? $this->header(), null, 'A1');
        $sheet->fromArray($rows, null, 'A2');

        $path = tempnam(sys_get_temp_dir(), 'payroll') . '.xlsx';
        (new XlsxWriter($spreadsheet))->save($path);

        $this->tempFiles[] = $path;

        return $path;
    }

    private function payrollRows(): array
    {
        return [
            // บุคลากรจริง
            ['ข้าราชการ', '1111111111111', 'สมชาย', 'ใจดี', '1234567890', 20000, 1000, 1500, 3000, 5500, 31000, 25000],
            ['ลูกจ้าง', '2222222222222', 'สมหญิง', 'รักดี', '0987654321', 18000, 1000, 0, 500, 0, 19500, 15000],
            // แถว "รวมยอด" ท้ายไฟล์ — มีตัวเลข แต่ไม่มีเลขบัตรประชาชน
            ['', '', '', '', '', 38000, 2000, 1500, 3500, 5500, 50500, 40000],
            // แถว subtotal อีกแบบ — มีแค่ยอดรวม
            ['', '', '', '', '', 0, 0, 0, 0, 0, 50500, 0],
        ];
    }

    public function test_rows_without_a_citizen_id_are_skipped_not_imported_as_staff(): void
    {
        $path = $this->writePayrollFile($this->payrollRows());

        $import = app(ImportService::class)->process($path, 'payroll.xlsx', $this->user->id);

        $this->assertSame(4, $import->total_rows);
        $this->assertSame(2, $import->success_rows);
        $this->assertSame(2, $import->skipped_rows);

        $this->assertSame(2, Payroll::where('import_id', $import->id)->count());

        // แถวรวมยอด 38,000 ต้องไม่ถูกนับรวม
        $this->assertSame(38000.0, (float) Payroll::where('import_id', $import->id)->sum('salary'));
    }

    public function test_income_columns_including_p4p_are_read_from_the_file(): void
    {
        $path = $this->writePayrollFile($this->payrollRows());

        $import = app(ImportService::class)->process($path, 'payroll.xlsx', $this->user->id);

        $this->assertSame(5500.0, (float) Payroll::where('import_id', $import->id)->sum('p4p_income'));
        $this->assertSame(3500.0, (float) Payroll::where('import_id', $import->id)->sum('overtime'));
        $this->assertSame(1500.0, (float) Payroll::where('import_id', $import->id)->sum('position_allowance'));
    }

    public function test_the_same_file_can_be_imported_again_as_a_new_snapshot(): void
    {
        $path = $this->writePayrollFile($this->payrollRows());

        $first = app(ImportService::class)->process($path, 'payroll.xlsx', $this->user->id);
        $second = app(ImportService::class)->process($path, 'payroll.xlsx', $this->user->id);

        $this->assertNotSame($first->id, $second->id);

        // นำเข้าซ้ำต้องได้ข้อมูลใหม่ ไม่ใช่ถูกนับเป็น duplicate ทั้งไฟล์
        $this->assertSame(2, $second->success_rows);
        $this->assertSame(0, $second->duplicate_rows);
        $this->assertSame(2, Payroll::where('import_id', $second->id)->count());

        $this->assertSame(2, Import::count());
    }

    public function test_duplicate_rows_inside_the_same_file_are_still_detected(): void
    {
        $path = $this->writePayrollFile([
            ['ข้าราชการ', '1111111111111', 'สมชาย', 'ใจดี', '1234567890', 20000, 0, 0, 0, 0, 20000, 20000],
            ['ข้าราชการ', '1111111111111', 'สมชาย', 'ใจดี', '1234567890', 20000, 0, 0, 0, 0, 20000, 20000],
        ]);

        $import = app(ImportService::class)->process($path, 'payroll.xlsx', $this->user->id);

        $this->assertSame(1, $import->success_rows);
        $this->assertSame(1, $import->duplicate_rows);
    }

    public function test_the_parser_reports_income_columns_missing_from_the_file(): void
    {
        // ไฟล์ที่ไม่มีคอลัมน์ P4P เลย
        $path = $this->writePayrollFile(
            [['ข้าราชการ', '1111111111111', 'สมชาย', 'ใจดี', '1234567890', 20000, 0, 0, 20000, 20000]],
            ['ประเภท', 'บัตรประชาชน', 'ชื่อ', 'นามสกุล', 'เลขที่บัญชี', 'เงินเดือน', 'พตส', 'ล่วงเวลา', 'รวมรายรับ', 'รับจริง'],
        );

        $parser = new XlsxParser();
        $parser->parse($path);

        $this->assertSame(['เงิน P4P'], $parser->missingReserveIncomeFields());
    }

    public function test_import_records_a_warning_when_a_row_cannot_be_linked_to_staff(): void
    {
        $status = EmployeeStatus::create(['name' => 'ปฏิบัติงานอยู่', 'sort_order' => 1]);

        Employee::create([
            'citizen_id' => '1509901045049',
            'first_name' => 'ปาริฉัตร',
            'last_name'  => 'สิทธิวงค์',
            'status_id'  => $status->id,
        ]);

        $path = $this->writePayrollFile([
            // เลขบัตรผิด 1 หลัก แต่ชื่อตรงกับบุคลากรที่ปฏิบัติงานอยู่
            ['ข้าราชการ', '1509901075049', 'นางสาวปาริฉัตร', 'สิทธิวงค์', '1234567890', 20000, 0, 0, 0, 0, 20000, 20000],
        ]);

        $import = app(ImportService::class)->process($path, 'payroll.xlsx', $this->user->id);

        $this->assertSame(1, $import->success_rows);

        $linkWarnings = collect($import->row_errors ?? [])->where('type', 'link')->values();

        $this->assertCount(1, $linkWarnings);
        $this->assertSame('name_match', $linkWarnings[0]['reason']);
        $this->assertSame('1509901045049', $linkWarnings[0]['employee_citizen_id']);
    }

    public function test_no_income_column_is_reported_missing_when_the_file_has_them_all(): void
    {
        $path = $this->writePayrollFile($this->payrollRows());

        $parser = new XlsxParser();
        $parser->parse($path);

        $this->assertSame([], $parser->missingReserveIncomeFields());
    }
}
