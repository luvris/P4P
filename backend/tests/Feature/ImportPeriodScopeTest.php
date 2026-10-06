<?php

namespace Tests\Feature;

use App\Models\Payroll;
use App\Models\User;
use App\Services\ImportTemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * ตัวเลือกขอบเขตงวดตอนนำเข้า — งวดเดียว / หลายงวด / ทั้งปีงบประมาณ
 *
 * ไฟล์ที่มีคอลัมน์ปี/เดือนของตัวเอง ระบบจะใช้งวดนั้นเสมอ
 * ค่าที่ผู้ใช้เลือกมีไว้เพื่อ (ก) เติมงวดให้แถวที่ไฟล์ไม่ได้ระบุ
 * และ (ข) ตรวจว่าไฟล์ตรงกับขอบเขตที่ผู้ใช้ยืนยันหรือไม่
 */
class ImportPeriodScopeTest extends TestCase
{
    use RefreshDatabase;

    private array $tempFiles = [];

    private User $finance;

    protected function setUp(): void
    {
        parent::setUp();

        $this->finance = User::create([
            'name'     => 'Scope Tester',
            'username' => 'scope_finance',
            'email'    => 'scope_finance@example.test',
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
     * สร้างไฟล์ payroll จากแบบฟอร์มจริง แล้วเขียนแถวตามที่กำหนด
     *
     * @param  array<int, array{seq: int, year: ?int, month: ?string, name: string}>  $rows
     */
    private function writeFile(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'scope') . '.xlsx';
        app(ImportTemplateService::class)->writePayrollTemplate($path);
        $this->tempFiles[] = $path;

        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getActiveSheet();

        // หัวตารางอยู่แถว 1 แถวตัวอย่างอยู่แถว 3 (แถว 2 ว่างตามแบบฟอร์ม)
        $index = array_flip($sheet->toArray()[0]);
        $cell = fn (string $column, int $row) => Coordinate::stringFromColumnIndex($index[$column] + 1) . $row;

        foreach ($rows as $i => $row) {
            $rowNumber = 3 + $i;

            $sheet->setCellValue($cell('ลำดับที่', $rowNumber), $row['seq']);
            $sheet->setCellValue($cell('ปี', $rowNumber), $row['year']);
            $sheet->setCellValue($cell('เดือน', $rowNumber), $row['month']);
            $sheet->setCellValue($cell('ชื่อ', $rowNumber), $row['name']);
            $sheet->setCellValue($cell('นามสกุล', $rowNumber), 'ทดสอบงวด');
            $sheet->setCellValue($cell('เงินเดือน', $rowNumber), 30000);
            $sheet->setCellValue($cell('รวมรายรับทางตรง', $rowNumber), 30000);
            $sheet->setCellValue($cell('รวมรายรับทางอ้อม', $rowNumber), 0);
            $sheet->setCellValue($cell('ยอดรวมรายรับทั้งหมด รายบุคคล', $rowNumber), 30000);
        }

        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save($path);

        return $path;
    }

    private function upload(string $path, array $payload = [])
    {
        return $this->actingAs($this->finance)->post('/api/imports', array_merge([
            'file' => UploadedFile::fake()->createWithContent('scope.xlsx', file_get_contents($path)),
        ], $payload));
    }

    // ============ งวดเดียว ============

    public function test_single_month_scope_fills_rows_that_have_no_month(): void
    {
        // ไฟล์ไม่ระบุเดือนเลย — งวดเดียวคือค่าที่ระบบเติมให้ได้
        $path = $this->writeFile([
            ['seq' => 1, 'year' => null, 'month' => null, 'name' => 'สมชาย'],
            ['seq' => 2, 'year' => null, 'month' => null, 'name' => 'สมหญิง'],
        ]);

        $this->upload($path, [
            'scope'         => 'month',
            'fiscal_year'   => 2569,
            'period_month'  => 5,
        ])->assertCreated();

        $this->assertSame(2, Payroll::count());
        // เดือน 5 ของปีงบ 2569 = พฤษภาคม 2569
        $this->assertSame([5, 5], Payroll::pluck('period_month')->all());
        $this->assertSame([2569, 2569], Payroll::pluck('fiscal_year')->all());
        $this->assertSame([2569, 2569], Payroll::pluck('period_year')->all());
    }

    public function test_single_month_scope_never_overwrites_the_month_in_the_file(): void
    {
        $path = $this->writeFile([
            ['seq' => 1, 'year' => 2569, 'month' => 'มกราคม', 'name' => 'สมชาย'],
        ]);

        $this->upload($path, [
            'scope'        => 'month',
            'fiscal_year'  => 2569,
            'period_month' => 5,
        ])->assertCreated();

        // ไฟล์บอก ม.ค. ชัดเจน — ต้องไม่ถูกค่าที่เลือกทับ
        $this->assertSame(1, Payroll::first()->period_month);
    }

    public function test_a_single_month_scope_warns_when_the_file_has_other_months(): void
    {
        $path = $this->writeFile([
            ['seq' => 1, 'year' => 2569, 'month' => 'มกราคม', 'name' => 'สมชาย'],
            ['seq' => 2, 'year' => 2569, 'month' => 'มีนาคม', 'name' => 'สมหญิง'],
        ]);

        $response = $this->upload($path, [
            'scope'        => 'month',
            'fiscal_year'  => 2569,
            'period_month' => 5,
        ])->assertCreated();

        $this->assertNotEmpty($response->json('scope_warnings'));
        $this->assertSame([1, 3], Payroll::orderBy('period_month')->pluck('period_month')->all());
    }

    // ============ หลายงวด ============

    public function test_multiple_months_scope_accepts_a_file_that_matches(): void
    {
        $path = $this->writeFile([
            ['seq' => 1, 'year' => 2569, 'month' => 'มกราคม', 'name' => 'สมชาย'],
            ['seq' => 2, 'year' => 2569, 'month' => 'กุมภาพันธ์', 'name' => 'สมหญิง'],
        ]);

        $response = $this->upload($path, [
            'scope'          => 'months',
            'fiscal_year'    => 2569,
            'period_months'  => [1, 2],
        ])->assertCreated();

        $this->assertSame([], $response->json('scope_warnings'));
        $this->assertSame(2, Payroll::count());
    }

    public function test_multiple_months_scope_rejects_a_file_with_another_month(): void
    {
        $path = $this->writeFile([
            ['seq' => 1, 'year' => 2569, 'month' => 'มกราคม', 'name' => 'สมชาย'],
            ['seq' => 2, 'year' => 2569, 'month' => 'มีนาคม', 'name' => 'สมหญิง'],
        ]);

        $this->upload($path, [
            'scope'         => 'months',
            'fiscal_year'   => 2569,
            'period_months' => [1, 2],
        ])->assertStatus(422)->assertJsonValidationErrors('period_months');

        // ต้องไม่มีแถวใดถูกเขียน เพราะงวดไม่ตรงที่ผู้ใช้ยืนยัน
        $this->assertSame(0, Payroll::count());
    }

    public function test_multiple_months_scope_refuses_to_guess_rows_without_a_month(): void
    {
        $path = $this->writeFile([
            ['seq' => 1, 'year' => null, 'month' => null, 'name' => 'สมชาย'],
        ]);

        $this->upload($path, [
            'scope'         => 'months',
            'fiscal_year'   => 2569,
            'period_months' => [1, 2],
        ])->assertStatus(422)->assertJsonValidationErrors('period_months');

        $this->assertSame(0, Payroll::count());
    }

    // ============ ทั้งปีงบประมาณ ============

    public function test_fiscal_year_scope_accepts_a_whole_year_file(): void
    {
        $path = $this->writeFile([
            ['seq' => 1, 'year' => 2568, 'month' => 'ตุลาคม', 'name' => 'สมชาย'],
            ['seq' => 2, 'year' => 2569, 'month' => 'มกราคม', 'name' => 'สมหญิง'],
            ['seq' => 3, 'year' => 2569, 'month' => 'กันยายน', 'name' => 'ประเสริฐ'],
        ]);

        $this->upload($path, [
            'scope'       => 'year',
            'fiscal_year' => 2569,
        ])->assertCreated();

        // ต.ค. 2568 ถึง ก.ย. 2569 = ปีงบ 2569 ทั้งหมด
        $this->assertSame([2569, 2569, 2569], Payroll::pluck('fiscal_year')->all());
        $this->assertSame(3, Payroll::count());
    }

    public function test_fiscal_year_scope_rejects_a_file_from_another_year(): void
    {
        $path = $this->writeFile([
            ['seq' => 1, 'year' => 2569, 'month' => 'มกราคม', 'name' => 'สมชาย'],
            // ก.ย. 2569 เป็นงวดสุดท้ายของปีงบ 2569 แต่ ต.ค. 2569 เป็นปีงบ 2570
            ['seq' => 2, 'year' => 2569, 'month' => 'ตุลาคม', 'name' => 'สมหญิง'],
        ]);

        $this->upload($path, [
            'scope'       => 'year',
            'fiscal_year' => 2569,
        ])->assertStatus(422)->assertJsonValidationErrors('period_months');

        $this->assertSame(0, Payroll::count());
    }

    public function test_fiscal_year_scope_refuses_to_guess_rows_without_a_month(): void
    {
        $path = $this->writeFile([
            ['seq' => 1, 'year' => 2569, 'month' => 'มกราคม', 'name' => 'สมชาย'],
            ['seq' => 2, 'year' => null, 'month' => null, 'name' => 'สมหญิง'],
        ]);

        $this->upload($path, [
            'scope'       => 'year',
            'fiscal_year' => 2569,
        ])->assertStatus(422)->assertJsonValidationErrors('period_months');

        $this->assertSame(0, Payroll::count());
    }

    public function test_an_unknown_scope_is_rejected(): void
    {
        $path = $this->writeFile([
            ['seq' => 1, 'year' => 2569, 'month' => 'มกราคม', 'name' => 'สมชาย'],
        ]);

        $this->upload($path, ['scope' => 'ทั้งปี'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('scope');
    }

    public function test_a_rejected_file_is_not_kept_on_disk(): void
    {
        Storage::fake('local');

        $path = $this->writeFile([
            ['seq' => 1, 'year' => 2569, 'month' => 'ตุลาคม', 'name' => 'สมชาย'],
        ]);

        $this->upload($path, [
            'scope'       => 'year',
            'fiscal_year' => 2569,
        ])->assertStatus(422);

        // ต.ค. 2569 อยู่ในปีงบ 2570 ไม่ใช่ 2569 — ไฟล์ต้องถูกลบไม่ให้เป็นไฟล์กำพร้า
        $this->assertSame([], Storage::disk('local')->files('imports'));
    }

    public function test_a_guest_cannot_upload_with_a_scope(): void
    {
        $path = $this->writeFile([
            ['seq' => 1, 'year' => 2569, 'month' => 'มกราคม', 'name' => 'สมชาย'],
        ]);

        $this->post('/api/imports', [
            'file'         => UploadedFile::fake()->createWithContent('scope.xlsx', file_get_contents($path)),
            'scope'        => 'year',
            'fiscal_year'  => 2569,
        ])->assertUnauthorized();
    }
}