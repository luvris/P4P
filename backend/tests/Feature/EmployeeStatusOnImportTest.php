<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeStatus;
use App\Models\Payroll;
use App\Models\User;
use App\Services\ImportTemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * สถานะบุคลากรตอนนำเข้าไฟล์เงินเดือน
 *
 * ไฟล์เงินเดือนไม่มีคอลัมน์สถานะ ถ้าปล่อยให้พนักงานใหม่มีสถานะว่าง
 * ตัวกรองของฐานเงินสำรอง (เอาเฉพาะ "ปฏิบัติงานอยู่") จะตัดทุกคนออก
 * แล้วหน้าเงินสำรองจะขึ้น 0 ทุกงวดตั้งแต่การนำเข้าครั้งแรก
 */
class EmployeeStatusOnImportTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> */
    private array $tempFiles = [];

    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hr = User::create([
            'name'     => 'hr status tester',
            'username' => 'status_hr',
            'email'    => 'status_hr@example.test',
            'password' => bcrypt('secret-password'),
            'role'     => 'hr',
        ]);

        // ตารางอ้างอิงสถานะว่างเปล่าในเทสต์ (ไม่รัน seeder) จึงต้องสร้างเอง
        EmployeeStatus::create(['name' => EmployeeStatus::ACTIVE_NAME, 'color' => 'green', 'sort_order' => 1]);
        EmployeeStatus::create(['name' => 'ลาออก', 'color' => 'red', 'sort_order' => 3]);
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
     * สร้างไฟล์เงินเดือนจากแบบฟอร์มจริง แล้วเขียนแถวตามที่กำหนด
     *
     * @param  array<int, array{seq:int, year:int, month:string, citizen:string, name:string, income?:int}>  $rows
     */
    private function writeFile(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'empstatus') . '.xlsx';
        app(ImportTemplateService::class)->writePayrollTemplate($path);
        $this->tempFiles[] = $path;

        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getActiveSheet();

        // หัวตารางอยู่แถว 1 แถวตัวอย่างอยู่แถว 3 (แถว 2 ว่างตามแบบฟอร์ม)
        $index = array_flip($sheet->toArray()[0]);
        $cell = fn (string $column, int $row) => Coordinate::stringFromColumnIndex($index[$column] + 1) . $row;

        foreach ($rows as $i => $row) {
            $rowNumber = 3 + $i;
            $income = $row['income'] ?? 30000;

            $sheet->setCellValue($cell('ลำดับที่', $rowNumber), $row['seq']);
            $sheet->setCellValue($cell('ปี', $rowNumber), $row['year']);
            $sheet->setCellValue($cell('เดือน', $rowNumber), $row['month']);
            $sheet->setCellValue($cell('ชื่อ', $rowNumber), $row['name']);
            $sheet->setCellValue($cell('นามสกุล', $rowNumber), 'ทดสอบสถานะ');
            // ต้องมีเลขบัตรประชาชน ไม่งั้นแถวจะผูกกับทะเบียนบุคลากรไม่ได้
            $sheet->setCellValueExplicit(
                $cell('ID CARD', $rowNumber),
                $row['citizen'],
                \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING
            );
            $sheet->setCellValue($cell('เงินเดือน', $rowNumber), $income);
            $sheet->setCellValue($cell('รวมรายรับทางตรง', $rowNumber), $income);
            $sheet->setCellValue($cell('รวมรายรับทางอ้อม', $rowNumber), 0);
            $sheet->setCellValue($cell('ยอดรวมรายรับทั้งหมด รายบุคคล', $rowNumber), $income);
        }

        (new Xlsx($spreadsheet))->save($path);

        return $path;
    }

    private function upload(string $path)
    {
        return $this->actingAs($this->hr)->post('/api/imports', [
            'file' => UploadedFile::fake()->createWithContent('payroll.xlsx', file_get_contents($path)),
        ]);
    }

    private function annualSummary(): array
    {
        return $this->actingAs($this->hr)
            ->getJson('/api/hr/reserve-fund/annual?fiscal_year=2569&percent=5')
            ->assertOk()
            ->json();
    }

    private function activeStatusId(): int
    {
        return (int) EmployeeStatus::where('name', EmployeeStatus::ACTIVE_NAME)->value('id');
    }

    // ============ การนำเข้าครั้งแรก ============

    public function test_the_first_import_puts_every_new_employee_on_active_status(): void
    {
        // ระบบต้องมีสถานะ "ปฏิบัติงานอยู่" ให้ใช้เป็นค่าเริ่มต้น
        $this->assertGreaterThan(0, $this->activeStatusId(), 'ไม่พบสถานะ "ปฏิบัติงานอยู่" ในระบบ');

        $path = $this->writeFile([
            ['seq' => 1, 'year' => 2569, 'month' => 'มกราคม', 'citizen' => '1111111111111', 'name' => 'สมชาย'],
            ['seq' => 2, 'year' => 2569, 'month' => 'มกราคม', 'citizen' => '2222222222222', 'name' => 'สมหญิง'],
        ]);

        $this->upload($path)->assertCreated();

        $this->assertSame(2, Employee::count());
        $this->assertSame(0, Employee::whereNull('status_id')->count(), 'พนักงานใหม่ต้องไม่ถูกทิ้งสถานะว่าง');
        $this->assertSame(2, Employee::where('status_id', $this->activeStatusId())->count());
    }

    public function test_a_first_import_produces_a_non_zero_reserve_fund_base(): void
    {
        // นี่คือหน้าที่ใช้จริง — นำเข้าครั้งแรกแล้วต้องเห็นยอด ไม่ใช่ 0
        $path = $this->writeFile([
            ['seq' => 1, 'year' => 2569, 'month' => 'มกราคม', 'citizen' => '1111111111111', 'name' => 'สมชาย'],
            ['seq' => 2, 'year' => 2569, 'month' => 'มกราคม', 'citizen' => '2222222222222', 'name' => 'สมหญิง'],
        ]);

        $this->upload($path)->assertCreated();

        $summary = $this->annualSummary()['summary'];

        $this->assertSame(2, (int) $summary['total_employees']);
        $this->assertSame(60000.0, (float) $summary['total_income_base']);
        $this->assertSame(3000.0, (float) $summary['total_reserve'], '5% ของ 60,000');
    }

    // ============ การนำเข้าซ้ำ ============

    public function test_reimporting_does_not_overwrite_a_status_set_by_hand(): void
    {
        $path = $this->writeFile([
            ['seq' => 1, 'year' => 2569, 'month' => 'มกราคม', 'citizen' => '1111111111111', 'name' => 'สมชาย'],
        ]);

        $this->upload($path)->assertCreated();
        $this->assertSame($this->activeStatusId(), (int) Employee::firstOrFail()->status_id);

        // เจ้าหน้าที่แก้สถานะเองแล้ว การอัปโหลดไฟล์เดิมซ้ำต้องไม่เปลี่ยนกลับ
        $resignedId = (int) EmployeeStatus::where('name', 'ลาออก')->value('id');
        Employee::firstOrFail()->update(['status_id' => $resignedId, 'latest_salary' => 1]);

        $this->upload($path)->assertCreated();

        $employee = Employee::firstOrFail();

        $this->assertSame($resignedId, (int) $employee->status_id, 'สถานะที่แก้ด้วยมือต้องไม่ถูกทับ');
        // ฟิลด์อื่นที่อยู่ในไฟล์ยังถูกอัปเดตตามปกติ
        $this->assertSame(30000.0, (float) $employee->latest_salary);
    }

    // ============ ตัวกรองยังต้องมีผล ============

    public function test_employees_with_a_non_active_status_are_left_out_of_the_base(): void
    {
        $path = $this->writeFile([
            ['seq' => 1, 'year' => 2569, 'month' => 'มกราคม', 'citizen' => '1111111111111', 'name' => 'อยู่ต่อ'],
            ['seq' => 2, 'year' => 2569, 'month' => 'มกราคม', 'citizen' => '2222222222222', 'name' => 'ลาออกแล้ว'],
        ]);

        $this->upload($path)->assertCreated();

        Employee::where('citizen_id', '2222222222222')->update([
            'status_id' => (int) EmployeeStatus::where('name', 'ลาออก')->value('id'),
        ]);

        $summary = $this->annualSummary()['summary'];

        // เหลือเฉพาะคนที่ยังปฏิบัติงานอยู่ — เงินเดือนของคนที่ลาออกแล้วต้องไม่ถูกนับ
        $this->assertSame(1, (int) $summary['total_employees']);
        $this->assertSame(30000.0, (float) $summary['total_income_base']);
        $this->assertSame(2, Payroll::count(), 'แถวเงินเดือนยังอยู่ครบ แต่ไม่ถูกนับในฐาน');
    }
}
