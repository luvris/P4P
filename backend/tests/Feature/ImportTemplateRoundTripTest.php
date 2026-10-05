<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeStatus;
use App\Models\Import;
use App\Models\Payroll;
use App\Models\User;
use App\Services\ImportService;
use App\Services\ImportTemplateService;
use App\Services\NewFormatEmployeeImportService;
use App\Services\Parsers\NewFormatPayrollParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * ผู้ใช้ดาวน์โหลดแบบฟอร์ม → กรอกข้อมูล → อัปโหลดกลับเข้าระบบ
 *
 * คือสถานการณ์จริงที่ฟีเจอร์นี้ต้องทำให้ได้ ไม่ใช่แค่ไฟล์เปิดได้
 */
class ImportTemplateRoundTripTest extends TestCase
{
    use RefreshDatabase;

    private array $tempFiles = [];

    private User $finance;

    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->finance = User::create([
            'name' => 'Finance RT', 'username' => 'rt_finance',
            'email' => 'rt_finance@example.test',
            'password' => bcrypt('secret-password'), 'role' => 'finance',
        ]);

        $this->hr = User::create([
            'name' => 'HR RT', 'username' => 'rt_hr',
            'email' => 'rt_hr@example.test',
            'password' => bcrypt('secret-password'), 'role' => 'hr',
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
     * ดาวน์โหลดแบบฟอร์ม → เติมข้อมูลสมมติ → บันทึก
     *
     * เลียนแบบสิ่งที่ผู้ใช้ทำจริง: เปิดไฟล์ แก้ช่อง แล้วอัปกลับ
     */
    private function filledTemplate(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'rt') . '.xlsx';
        app(ImportTemplateService::class)->writePayrollTemplate($path);
        $this->tempFiles[] = $path;

        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getActiveSheet();

        // ตำแหน่งคอลัมน์ต้องอ่านจากไฟล์จริง เพราะคอลัมน์ที่ระบบเพิ่มอาจถูกยัดกลางไฟล์
        $index = array_flip($sheet->toArray()[0]);

        // ผู้ใช้เขียนทับแถวตัวอย่าง (แถว 2) แล้วเพิ่มแถวของตัวเองต่อจากนั้น
        $citizens = ['1000000000001', '1000000000002'];

        foreach ($citizens as $i => $citizenId) {
            $row = 2 + $i;

            $sheet->setCellValue($this->cell('ลำดับที่', $index, $row), $i + 1);
            $sheet->setCellValue($this->cell('ปี', $index, $row), 2569);
            $sheet->setCellValue($this->cell('เดือน', $index, $row), 'มกราคม');
            $sheet->setCellValue($this->cell('คำนำหน้า', $index, $row), 'นาง');
            $sheet->setCellValue($this->cell('ชื่อ', $index, $row), 'ทดสอบ' . $i);
            $sheet->setCellValue($this->cell('นามสกุล', $index, $row), 'ระบบ');
            $sheet->setCellValue($this->cell('ประเภท', $index, $row), 'ข้าราชการ');
            $sheet->setCellValue($this->cell('ตำแหน่ง', $index, $row), 'พยาบาลวิชาชีพ');
            $sheet->setCellValue($this->cell('ตำแหน่งเลขที่', $index, $row), '000' . ($i + 1));
            $sheet->setCellValue($this->cell('ID CARD', $index, $row), $citizenId);
            $sheet->setCellValue($this->cell('เลขที่บัญชี', $index, $row), '111000000' . $i);

            $salary = 25000 + $i * 1000;

            $sheet->setCellValue($this->cell('เงินเดือน', $index, $row), $salary);
            $sheet->setCellValue($this->cell('รวมรายรับทางตรง', $index, $row), $salary);
            $sheet->setCellValue($this->cell('รวมรายรับทางอ้อม', $index, $row), 0);
            $sheet->setCellValue($this->cell('ยอดรวมรายรับทั้งหมด รายบุคคล', $index, $row), $salary);
            $sheet->setCellValue($this->cell('หมายเหตุ', $index, $row), '');
        }

        // ลบแถวตัวอย่างที่เหลือ (ถ้ามี) ตามที่คำอธิบายในไฟล์กำหนด
        $lastRow = $sheet->getHighestRow();
        for ($row = 2 + count($citizens); $row <= $lastRow; $row++) {
            $sheet->removeRow($row);
        }

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save($path);

        return $path;
    }

    private function cell(string $column, array $index, int $row): string
    {
        return \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index[$column] + 1) . $row;
    }

    public function test_a_filled_template_imports_into_payroll_without_errors(): void
    {
        $import = app(ImportService::class)->process(
            $this->filledTemplate(),
            'filled-template.xlsx',
            $this->finance->id,
            new NewFormatPayrollParser()
        );

        $this->assertSame(2, $import->success_rows, 'ทั้ง 2 คนต้องเข้าได้');
        $this->assertSame(0, $import->error_rows);
        $this->assertSame(0, $import->skipped_rows);

        $payroll = Payroll::where('import_id', $import->id)->first();
        $this->assertSame('1000000000001', $payroll->citizen_id);
        $this->assertSame(2569, (int) $payroll->fiscal_year);
        $this->assertSame(1, (int) $payroll->period_month);
        $this->assertEqualsWithDelta(25000, (float) $payroll->total_income, 0.001);
    }

    public function test_a_filled_template_creates_the_staff_registry(): void
    {
        app(ImportService::class)->process(
            $this->filledTemplate(),
            'filled-template.xlsx',
            $this->finance->id,
            new NewFormatPayrollParser()
        );

        $data = app(NewFormatEmployeeImportService::class)->parse($this->filledTemplate());
        $this->assertCount(2, $data['data']);

        // ลงทะเบียนผ่าน HTTP ตาม flow จริง (หน้าอัปโหลดเดียว ใช้ได้ทุก role)
        $response = $this->actingAs($this->hr)
            ->post('/api/imports', [
                'file' => \Illuminate\Http\UploadedFile::fake()->createWithContent(
                    'filled.xlsx',
                    file_get_contents($this->filledTemplate())
                ),
            ]);

        $response->assertCreated();

        // ไฟล์เดียวต้องได้ทั้งทะเบียนบุคลากรและแถวเงินเดือน
        $this->assertNotNull($response->json('employee'));
        $this->assertSame(2, $response->json('employee.total'));
        $this->assertGreaterThan(0, $response->json('payroll.success_rows'));

        $this->assertSame(2, Employee::count());
        $employee = Employee::where('citizen_id', '1000000000001')->first();
        $this->assertNotNull($employee);
        $this->assertSame('ทดสอบ0', $employee->first_name);
        $this->assertNotNull($employee->position_id, 'ตำแหน่งควรถูกสร้างให้อัตโนมัติ');
    }

    public function test_template_round_trip_feeds_the_reserve_fund(): void
    {
        app(ImportService::class)->process(
            $this->filledTemplate(),
            'filled-template.xlsx',
            $this->finance->id,
            new NewFormatPayrollParser()
        );

        // สร้างทะเบียน + สถานะ เพื่อให้เงินสำรองนับได้
        $status = EmployeeStatus::create(['name' => 'ปฏิบัติงานอยู่', 'sort_order' => 1]);

        foreach (['1000000000001', '1000000000002'] as $citizenId) {
            Employee::create([
                'citizen_id' => $citizenId,
                'first_name' => 'ทดสอบ',
                'last_name'  => 'ระบบ',
                'status_id'  => $status->id,
            ]);
        }

        $response = $this->actingAs($this->hr)
            ->getJson('/api/hr/reserve-fund/annual?fiscal_year=2569&percent=3')
            ->assertOk();

        $this->assertSame(2, $response->json('summary.total_employees'));
        $this->assertEqualsWithDelta(51000, $response->json('summary.total_income_base'), 0.001);
    }
}
