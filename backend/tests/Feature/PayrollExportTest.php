<?php

namespace Tests\Feature;

use App\Models\Payroll;
use App\Models\PayrollExtraColumn;
use App\Models\User;
use App\Services\PayrollExportService;
use App\Services\Parsers\NewFormatPayrollParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Tests\TestCase;

/**
 * ปุ่ม export ข้อมูลในหน้านำเข้าข้อมูล
 *
 * ไฟล์ที่ได้ต้องเปิดแก้ต่อได้เลย จึงต้องมีหัวตารางเหมือนแบบฟอร์มทุกช่อง
 * และต้องอัปโหลดกลับเข้าระบบได้ (ไม่มีแถวตัวอย่างปะปน)
 */
class PayrollExportTest extends TestCase
{
    use RefreshDatabase;

    protected User $hr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hr = User::create([
            'name'     => 'hr tester',
            'username' => 'export_hr',
            'email'    => 'export_hr@example.test',
            'password' => bcrypt('secret-password'),
            'role'     => 'hr',
        ]);
    }

    protected function makePayroll(array $attributes = []): Payroll
    {
        return Payroll::create([
            'seq_number'            => 1,
            'period_year'           => 2569,
            'period_month'          => 1,
            'fiscal_year'           => 2569,
            'employee_type'         => 'ข้าราชการ',
            'prefix'                => 'นาย',
            'first_name'            => 'สมชาย',
            'last_name'             => 'ทดสอบ',
            'position_name'         => 'พยาบาลวิชาชีพ',
            'position_number'       => '0001',
            'citizen_id'            => '1111111111111',
            'bank_account'          => '012-000-001',
            'salary'                => 30000,
            'total_direct_income'  => 30000,
            'total_income'          => 30000,
            ...$attributes,
        ]);
    }

    /**
     * อ่านชีตของไฟล์ที่ดาวน์โหลดได้จริง
     *
     * @return \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet
     */
    protected function downloadedSheet($response)
    {
        $path = tempnam(sys_get_temp_dir(), 'export_');
        file_put_contents($path, $response->streamedContent());

        $sheet = IOFactory::load($path)->getActiveSheet();
        @unlink($path);

        return $sheet;
    }

    /**
     * ค่าในไฟล์ที่ดาวน์โหลดได้จริง
     *
     * @return array<int, array<int, mixed>>
     */
    protected function readDownloadedFile($response): array
    {
        return $this->downloadedSheet($response)->toArray();
    }

    // ============ รายการงวด ============

    public function test_periods_are_listed_newest_first(): void
    {
        $this->makePayroll(['period_month' => 1, 'period_year' => 2569]);
        $this->makePayroll(['seq_number' => 2, 'period_month' => 5, 'period_year' => 2569]);
        $this->makePayroll(['seq_number' => 3, 'period_month' => 11, 'period_year' => 2568]);

        $response = $this->actingAs($this->hr)->getJson('/api/imports/periods');

        $response->assertOk()
            ->assertJsonPath('data.0.period_month', 5)
            ->assertJsonPath('data.0.label', 'พฤษภาคม 2569')
            ->assertJsonPath('data.0.rows', 1)
            ->assertJsonPath('data.1.period_month', 1)
            ->assertJsonPath('data.2.period_month', 11);
    }

    public function test_periods_are_empty_before_any_import(): void
    {
        $this->actingAs($this->hr)
            ->getJson('/api/imports/periods')
            ->assertOk()
            ->assertJsonPath('data', []);
    }

    // ============ ไฟล์ export ============

    public function test_exported_file_has_the_same_headers_as_the_template(): void
    {
        $this->makePayroll();

        $rows = $this->readDownloadedFile(
            $this->actingAs($this->hr)->get('/api/imports/export?period_month=1&period_year=2569')
        );

        $expected = app(\App\Services\PayrollExtraColumnService::class)
            ->columnsWithExtras(NewFormatPayrollParser::TEMPLATE_COLUMNS);

        // แถว 1 = หัวตาราง (เหมือนแบบฟอร์มทุกช่อง รวมคอลัมน์ที่ผู้ใช้เพิ่ม)
        $this->assertSame($expected, array_slice($rows[0], 0, count($expected)));
    }

    public function test_exported_file_starts_with_real_data_and_no_sample_row(): void
    {
        $this->makePayroll();

        $rows = $this->readDownloadedFile(
            $this->actingAs($this->hr)->get('/api/imports/export?period_month=1&period_year=2569')
        );

        $headers = $rows[0];
        $value = fn (string $column) => $rows[1][array_search($column, $headers, true)] ?? null;

        // ไม่มีแถวตัวอย่าง — แถวแรกหลังหัวตารางต้องเป็นข้อมูลจริงทันที
        $this->assertSame('สมชาย', $value('ชื่อ'));
        $this->assertSame('ทดสอบ', $value('นามสกุล'));
        $this->assertSame('พยาบาลวิชาชีพ', $value('ตำแหน่ง'));
        $this->assertSame('012-000-001', $value('เลขที่บัญชี'));

        // งวดในไฟล์ต้องเป็น ปี พ.ศ. + ชื่อเดือนไทย เพื่อให้อัปโหลดกลับได้
        $this->assertEquals(2569, $value('ปี'));
        $this->assertSame('มกราคม', $value('เดือน'));
        $this->assertEquals(1, $value('ลำดับที่'));
    }

    public function test_exported_numbers_stay_numeric_for_reimport(): void
    {
        $this->makePayroll(['salary' => 31500.50, 'total_income' => 48000.25]);

        $sheet = $this->downloadedSheet(
            $this->actingAs($this->hr)->get('/api/imports/export?period_month=1&period_year=2569')
        );

        $headers = $sheet->rangeToArray('A1:ZZ1')[0];

        $cases = [
            'เงินเดือน' => 31500.5,
            'ยอดรวมรายรับทั้งหมด รายบุคคล' => 48000.25,
        ];

        foreach ($cases as $column => $expected) {
            $letter = Coordinate::stringFromColumnIndex((int) array_search($column, $headers, true) + 1);
            $cell = $sheet->getCell($letter . '2');

            // ต้องเป็นชนิดตัวเลขจริง ไม่ใช่ข้อความ ไม่งั้นฐานเงินสำรองจะผิดตอนอัปโหลดกลับ
            $this->assertSame(DataType::TYPE_NUMERIC, $cell->getDataType(), "{$column} ต้องเป็นตัวเลข");
            $this->assertSame($expected, (float) $cell->getValue());
        }
    }

    public function test_export_only_carries_the_chosen_period(): void
    {
        // เก็บลำดับเดิมจากไฟล์ต้นทาง (ข้ามงวด/ข้ามไฟล์) — ไฟล์ที่ export ต้องไล่ใหม่
        $this->makePayroll(['seq_number' => 2339, 'period_month' => 1, 'period_year' => 2569]);
        $this->makePayroll(['seq_number' => 90, 'period_month' => 2, 'period_year' => 2569]);
        $this->makePayroll(['seq_number' => 1200, 'period_month' => 1, 'period_year' => 2569, 'last_name' => 'ทดสอบสอง']);

        $rows = $this->readDownloadedFile(
            $this->actingAs($this->hr)->get('/api/imports/export?period_month=1&period_year=2569')
        );

        $this->assertCount(3, $rows);   // หัวตาราง + 2 แถวของงวดนี้
        $this->assertEquals([1, 2], array_column(array_slice($rows, 1), array_search('ลำดับที่', $rows[0], true)));
    }

    public function test_extra_columns_are_exported_in_their_configured_position(): void
    {
        PayrollExtraColumn::create([
            'name'          => 'ค่าครองชีพเฉพาะหน่วย',
            'key'           => 'living_unit',
            'before_column' => 'เงินเดือน',
            'data_type'     => 'text',
        ]);

        $this->makePayroll(['extra_data' => ['living_unit' => '1,500']]);

        $rows = $this->readDownloadedFile(
            $this->actingAs($this->hr)->get('/api/imports/export?period_month=1&period_year=2569')
        );

        $headers = $rows[0];
        $salaryAt = array_search('เงินเดือน', $headers, true);

        // คอลัมน์เพิ่มเติมต้องอยู่ตรงตำแหน่งที่ผู้ดูแลเลือกไว้ ไม่ใช่หลุดไปท้ายไฟล์
        $this->assertSame('ค่าครองชีพเฉพาะหน่วย', $headers[$salaryAt - 1]);
        $this->assertSame('1,500', $rows[1][$salaryAt - 1]);
    }

    public function test_exported_file_can_be_imported_back_without_errors(): void
    {
        $this->makePayroll();

        $path = tempnam(sys_get_temp_dir(), 'export_') . '.xlsx';
        $spreadsheet = app(PayrollExportService::class)->export(1, 2569)['spreadsheet'];
        (new XlsxWriter($spreadsheet))->save($path);

        $parsed = (new NewFormatPayrollParser())->parse($path);

        $this->assertCount(1, $parsed);
        $this->assertSame('สมชาย', $parsed[0]['first_name']);
        $this->assertSame(1, $parsed[0]['period_month']);
        $this->assertSame(2569, $parsed[0]['period_year']);
        $this->assertSame(30000.0, (float) $parsed[0]['salary']);

        @unlink($path);
    }

    public function test_a_period_without_data_still_downloads_the_headers(): void
    {
        $this->makePayroll();

        $rows = $this->readDownloadedFile(
            $this->actingAs($this->hr)->get('/api/imports/export?period_month=7&period_year=2569')
        );

        $this->assertCount(1, $rows);
        $this->assertContains('ชื่อ', $rows[0]);
    }

    public function test_export_requires_a_valid_period(): void
    {
        $this->actingAs($this->hr)
            ->getJson('/api/imports/export?period_month=13&period_year=2569')
            ->assertStatus(422)
            ->assertJsonValidationErrors('period_month');

        $this->actingAs($this->hr)
            ->getJson('/api/imports/export')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['period_month', 'period_year']);
    }

    public function test_a_guest_cannot_export(): void
    {
        $this->getJson('/api/imports/export?period_month=1&period_year=2569')->assertUnauthorized();
    }
}