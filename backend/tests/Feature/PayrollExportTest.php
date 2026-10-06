<?php

namespace Tests\Feature;

use App\Models\Duty;
use App\Models\Employee;
use App\Models\Group;
use App\Models\Payroll;
use App\Models\PayrollExtraColumn;
use App\Models\Work;
use App\Models\User;
use App\Services\PayrollExportService;
use App\Services\Parsers\NewFormatPayrollParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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

    public function test_fiscal_years_are_listed_newest_first_with_counts(): void
    {
        $this->makePayroll(['period_month' => 5, 'period_year' => 2569]);
        $this->makePayroll(['seq_number' => 2, 'period_month' => 9, 'period_year' => 2569]);
        $this->makePayroll(['seq_number' => 3, 'period_month' => 10, 'period_year' => 2567]);

        $response = $this->actingAs($this->hr)->getJson('/api/imports/periods');

        // ปีงบ 2569 คือ ต.ค. 2568 – ก.ย. 2569 งวด ต.ค. 2567 จึงอยู่ปีงบถัดไป (2568)
        $response->assertOk()
            ->assertJsonPath('years.0.fiscal_year', 2569)
            ->assertJsonPath('years.0.label', 'ปีงบประมาณ 2569')
            ->assertJsonPath('years.0.periods', 2)
            ->assertJsonPath('years.0.rows', 2)
            ->assertJsonPath('years.1.fiscal_year', 2568)
            ->assertJsonPath('years.1.periods', 1);
    }

    public function test_fiscal_years_are_empty_before_any_import(): void
    {
        $this->actingAs($this->hr)
            ->getJson('/api/imports/periods')
            ->assertOk()
            ->assertJsonPath('years', []);
    }

    // ============ ไฟล์ export รายงวด ============

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

    /**
 * คอลัมน์ที่เป็นข้อมูลระดับคน ต้องดึงจากทะเบียนบุคลากรได้ แม้ไฟล์เงินเดือนไม่ได้กรอก
 *
 * ถ้ายึดแค่ไฟล์ ข้อมูลที่ทะเบียนมีอยู่แล้วจะหายไปทุกครั้งที่ export
 */
public function test_extra_columns_fall_back_to_the_employee_registry(): void
    {
        $duty = Duty::create(['name' => 'ด้านการพยาบาล']);
        $group = Group::create(['name' => 'กลุ่มงานอุบัติเหตุ']);
        $work = Work::create(['name' => 'หน่วยฉุกเฉิน']);

        Employee::create([
            'citizen_id' => '1111111111111',
            'first_name' => 'สมชาย',
            'last_name'  => 'ทดสอบ',
            'duty_id'    => $duty->id,
            'group_id'   => $group->id,
            'work_id'    => $work->id,
        ]);

        // แถวเงินเดือนที่ไม่ได้กรอกคอลัมน์เหล่านี้ในไฟล์
        $this->makePayroll(['extra_data' => null]);

        $rows = $this->readDownloadedFile(
            $this->actingAs($this->hr)->get('/api/imports/export?period_month=1&period_year=2569')
        );

        foreach ([
            'ภารกิจ'    => 'ด้านการพยาบาล',
            'กลุ่มงาน' => 'กลุ่มงานอุบัติเหตุ',
            'งาน'       => 'หน่วยฉุกเฉิน',
        ] as $name => $value) {
            $at = array_search($name, $rows[0], true);

            $this->assertIsInt($at, "ไม่พบคอลัมน์ {$name} ในไฟล์ที่ export");
            $this->assertSame($value, (string) $rows[1][$at], "คอลัมน์ {$name} ควรดึงจากทะเบียนบุคลากร");
        }
    }

/**
 * ถ้ากรอกค่าในไฟล์ของงวดนั้น ค่านั้นต้องชนะข้อมูลในทะเบียน
 */
public function test_a_value_filled_in_the_file_wins_over_the_registry(): void
    {
        $duty = Duty::create(['name' => 'ด้านการพยาบาล']);

        Employee::create([
            'citizen_id' => '1111111111111',
            'first_name' => 'สมชาย',
            'last_name'  => 'ทดสอบ',
            'duty_id'    => $duty->id,
        ]);

        $this->makePayroll(['extra_data' => ['duty' => 'ย้ายหน่วย มิ.ย. 2569']]);

        $rows = $this->readDownloadedFile(
            $this->actingAs($this->hr)->get('/api/imports/export?period_month=1&period_year=2569')
        );

        $at = array_search('ภารกิจ', $rows[0], true);

        $this->assertSame('ย้ายหน่วย มิ.ย. 2569', (string) $rows[1][$at]);
    }

/**
 * คอลัมน์เพิ่มเติมต้องอยู่ครบวงจร: export → แก้ไฟล์ → นำเข้ากลับ → export อีกครั้ง
     *
     * คอลัมน์ที่เพิ่มจากหน้านำเข้าข้อมูลต้องได้ค่ากลับมา ไม่ใช่มีแต่หัวตาราง
     */
    public function test_extra_column_values_survive_the_export_import_cycle(): void
    {
        // ไฟล์ที่อัปโหลดจริงจะถูกเก็บไว้บนดิสก์ — ใช้ดิสก์จำลอง เพื่อไม่ให้เทสต์ไปโตนไฟล์บนเครื่อง
        Storage::fake('local');

        // สามคอลัมน์ที่ระบบมาพร้อม คือภารกิจ / กลุ่มงาน / งาน
        $extras = [
            'duty'       => 'ด้านการพยาบาล',
            'work_group' => 'กลุ่มงานอุบัติเหตุ',
            'work'       => 'หน่วยฉุกเฉิน',
        ];

        $this->makePayroll(['extra_data' => $extras]);

        $path = tempnam(sys_get_temp_dir(), 'extra_cycle_') . '.xlsx';
        (new XlsxWriter(app(PayrollExportService::class)->export(1, 2569)['spreadsheet']))->save($path);

        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getActiveSheet();
        $headers = $sheet->toArray()[0];

        foreach (['ภารกิจ' => 'duty', 'กลุ่มงาน' => 'work_group', 'งาน' => 'work'] as $name => $key) {
            $at = array_search($name, $headers, true);

            $this->assertIsInt($at, "ไม่พบคอลัมน์ {$name} ในไฟล์ที่ export");
            $this->assertSame(
                $extras[$key],
                (string) $sheet->getCell([$at + 1, 2])->getValue(),
                "คอลัมน์ {$name} มีแต่หัวตาราง ไม่มีค่า"
            );
        }

        // ย้ายไปงวดพฤษภาคมแล้วนำเข้ากลับ เพื่อให้ไม่ชนกับแถวเดิมของงวดมกราคม
        $sheet->setCellValue([array_search('เดือน', $headers, true) + 1, 2], 'พฤษภาคม');
        (new XlsxWriter($spreadsheet))->save($path);

        $this->actingAs($this->hr)->post('/api/imports', [
            'file' => new UploadedFile($path, 'cycle.xlsx', null, null, true),
        ])->assertCreated();

        $stored = Payroll::where('period_month', 5)->firstOrFail()->extra_data;

        $this->assertSame($extras['duty'], $stored['duty']);
        $this->assertSame($extras['work_group'], $stored['work_group']);
        $this->assertSame($extras['work'], $stored['work']);

        // export งวดพฤษภาคมอีกครั้ง ค่าต้องยังอยู่ครบ
        $again = $this->actingAs($this->hr)->get('/api/imports/export?period_month=5&period_year=2569');
        $this->assertSame(200, $again->getStatusCode(), 'export รอบสองไม่สำเร็จ: ' . $again->getContent());
        $rows = $this->readDownloadedFile($again);

        foreach (['ภารกิจ' => 'duty', 'กลุ่มงาน' => 'work_group', 'งาน' => 'work'] as $name => $key) {
            $at = array_search($name, $rows[0], true);

            $this->assertIsInt($at, "ไม่พบคอลัมน์ {$name} ในไฟล์ที่ export รอบที่สอง");
            $this->assertSame($extras[$key], (string) $rows[1][$at]);
        }

        @unlink($path);
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

    public function test_identifier_columns_are_written_as_text_not_scientific_numbers(): void
    {
        $this->makePayroll([
            'citizen_id'       => '3529900273541',
            'bank_account'     => '5360041536',
            'position_number'  => '0001',
        ]);

        $sheet = $this->downloadedSheet(
            $this->actingAs($this->hr)->get('/api/imports/export?period_month=1&period_year=2569')
        );

        $headers = $sheet->rangeToArray('A1:ZZ1')[0];

        $cases = [
            // เลข 13 หลักถ้าเป็นตัวเลข Excel จะแสดงเป็น 3.5299E+12
            'ID CARD'           => '3529900273541',
            'เลขที่บัญชี'        => '5360041536',
            // ศูนย์ขึ้นต้นต้องไม่หายไป
            'ตำแหน่งเลขที่'      => '0001',
        ];

        foreach ($cases as $column => $expected) {
            $letter = Coordinate::stringFromColumnIndex((int) array_search($column, $headers, true) + 1);
            $cell = $sheet->getCell($letter . '2');

            $this->assertSame(DataType::TYPE_STRING, $cell->getDataType(), "{$column} ต้องเป็นข้อความ");
            $this->assertSame($expected, $cell->getValue());
            // สิ่งที่ผู้ใช้เห็นบนหน้าจอ Excel/ตัวอ่านของไฟล์
            $this->assertSame($expected, $cell->getFormattedValue(), "{$column} ต้องแสดงครบ 13 หลัก ไม่เป็นเลขยกกำลัง");
            $this->assertSame('@', $cell->getStyle()->getNumberFormat()->getFormatCode());
        }
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

        // ระบุปีมาแต่ไม่ระบุงวด — ยังรู้ไม่ได้ว่าจะเอางวดไหนของปีนั้น
        $this->actingAs($this->hr)
            ->getJson('/api/imports/export?period_year=2569')
            ->assertStatus(422)
            ->assertJsonValidationErrors('period_month');
    }

    // ============ ไฟล์ export ทั้งปีงบประมาณ ============

    public function test_fiscal_year_export_only_carries_the_fiscal_year_months(): void
    {
        // ปีงบ 2569 = ต.ค. 2568 ถึง ก.ย. 2569
        $this->makePayroll(['period_month' => 10, 'period_year' => 2568]);
        $this->makePayroll(['seq_number' => 2, 'period_month' => 9, 'period_year' => 2569]);
        // อยู่นอกปีงบนี้
        $this->makePayroll(['seq_number' => 3, 'period_month' => 9, 'period_year' => 2568]);
        $this->makePayroll(['seq_number' => 4, 'period_month' => 10, 'period_year' => 2569]);

        $rows = $this->readDownloadedFile(
            $this->actingAs($this->hr)->get('/api/imports/export?fiscal_year=2569')
        );

        $months = array_column(array_slice($rows, 1), array_search('เดือน', $rows[0], true));

        $this->assertCount(3, $rows);   // หัวตาราง + 2 งวดของปีงบนี้
        $this->assertSame(['ตุลาคม', 'กันยายน'], $months);
    }

    public function test_fiscal_year_export_runs_from_october_to_september_and_renumbers_each_period(): void
    {
        $this->makePayroll(['period_month' => 9, 'period_year' => 2569, 'seq_number' => 7]);
        $this->makePayroll(['period_month' => 1, 'period_year' => 2569, 'seq_number' => 5]);
        $this->makePayroll(['period_month' => 10, 'period_year' => 2568, 'seq_number' => 9]);
        $this->makePayroll(['period_month' => 12, 'period_year' => 2568, 'seq_number' => 3]);

        $rows = $this->readDownloadedFile(
            $this->actingAs($this->hr)->get('/api/imports/export?fiscal_year=2569')
        );

        $body = array_slice($rows, 1);
        $monthAt = array_search('เดือน', $rows[0], true);
        $seqAt = array_search('ลำดับที่', $rows[0], true);

        // เรียงตามรอบปีงบจริง ไม่ใช่ ม.ค. → ธ.ค.
        $this->assertSame(
            ['ตุลาคม', 'ธันวาคม', 'มกราคม', 'กันยายน'],
            array_column($body, $monthAt)
        );

        // ลำดับที่ไล่ใหม่ต่องวด ไม่ต่อกันข้ามเดือน
        $this->assertSame([1, 1, 1, 1], array_map('intval', array_column($body, $seqAt)));
    }

    public function test_fiscal_year_export_keeps_the_template_headers(): void
    {
        $this->makePayroll(['period_month' => 11, 'period_year' => 2568]);

        $rows = $this->readDownloadedFile(
            $this->actingAs($this->hr)->get('/api/imports/export?fiscal_year=2569')
        );

        $expected = app(\App\Services\PayrollExtraColumnService::class)
            ->columnsWithExtras(NewFormatPayrollParser::TEMPLATE_COLUMNS);

        $this->assertSame($expected, array_slice($rows[0], 0, count($expected)));
        $this->assertCount(2, $rows);
    }

    public function test_fiscal_year_file_is_named_after_the_fiscal_year(): void
    {
        $this->makePayroll(['period_month' => 11, 'period_year' => 2568]);

        $response = $this->actingAs($this->hr)->get('/api/imports/export?fiscal_year=2569');

        $disposition = $response->headers->get('content-disposition');

        $this->assertStringContainsString(
            rawurlencode('ข้อมูลเงินเดือน-ปีงบประมาณ-2569.xlsx'),
            $disposition
        );
    }

    public function test_fiscal_year_export_can_be_imported_back(): void
    {
        $this->makePayroll(['period_month' => 10, 'period_year' => 2568, 'citizen_id' => '1111111111111']);
        $this->makePayroll([
            'seq_number'     => 2,
            'period_month'   => 5,
            'period_year'    => 2569,
            'citizen_id'     => '2222222222222',
            'first_name'     => 'สมหญิง',
            'last_name'      => 'ทดสอบสอง',
            'salary'         => 42000,
            'total_income'   => 45000,
        ]);

        $path = tempnam(sys_get_temp_dir(), 'export_') . '.xlsx';
        $spreadsheet = app(PayrollExportService::class)->exportFiscalYear(2569)['spreadsheet'];
        (new XlsxWriter($spreadsheet))->save($path);

        $parsed = (new NewFormatPayrollParser())->parse($path);

        // ทั้งสองงวดต้องกลับเข้าระบบได้ โดยแต่ละแถวใช้ปี/เดือนของตัวเอง
        $this->assertCount(2, $parsed);
        $this->assertSame([10, 5], array_column($parsed, 'period_month'));
        $this->assertSame([2568, 2569], array_column($parsed, 'period_year'));
        $this->assertSame('สมชาย', $parsed[0]['first_name']);
        $this->assertSame('สมหญิง', $parsed[1]['first_name']);
        $this->assertSame(42000.0, (float) $parsed[1]['salary']);

        @unlink($path);
    }

    public function test_export_refuses_a_period_and_a_fiscal_year_at_the_same_time(): void
    {
        $this->actingAs($this->hr)
            ->getJson('/api/imports/export?period_month=1&period_year=2569&fiscal_year=2569')
            ->assertStatus(422)
            ->assertJsonValidationErrors('fiscal_year');
    }

    public function test_a_guest_cannot_export(): void
    {
        $this->getJson('/api/imports/export?period_month=1&period_year=2569')->assertUnauthorized();
        $this->getJson('/api/imports/export?fiscal_year=2569')->assertUnauthorized();
    }
}