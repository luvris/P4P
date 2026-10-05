<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeStatus;
use App\Models\Payroll;
use App\Models\User;
use App\Services\ImportService;
use App\Services\Parsers\NewFormatPayrollParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Tests\TestCase;

/**
 * แถวที่นำเข้าแล้ว แต่ไม่เข้าฐานเงินสำรอง
 *
 * ระบบ match `payrolls` กับ `employees` ด้วยเลขบัตรประชาชน
 * แถวที่ไม่มี หรือไม่ตรง จะถูกตัดออกจากยอดเงินสำรองอย่างเงียบ ๆ
 * เทสต์นี้กันไม่ให้เงินหายโดยไม่มีใครรู้
 */
class PayrollUnlinkedRowsTest extends TestCase
{
    use RefreshDatabase;

    private array $tempFiles = [];

    private User $finance;

    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->finance = User::create([
            'name' => 'Finance UL', 'username' => 'ul_finance',
            'email' => 'ul_finance@example.test',
            'password' => bcrypt('secret-password'), 'role' => 'finance',
        ]);

        $this->hr = User::create([
            'name' => 'HR UL', 'username' => 'ul_hr',
            'email' => 'ul_hr@example.test',
            'password' => bcrypt('secret-password'), 'role' => 'hr',
        ]);

        $status = EmployeeStatus::create(['name' => 'ปฏิบัติงานอยู่', 'sort_order' => 1]);

        Employee::create([
            'citizen_id' => '1000000000001',
            'first_name' => 'สมชาย', 'last_name' => 'ใจดี',
            'status_id'  => $status->id,
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
     * ไฟล์ 1 งวด — คนในทะเบียน 1 คน + คนที่ไม่มีในทะเบียน 1 คน
     */
    private function fileWithUnlinkedRow(): string
    {
        $columns = NewFormatPayrollParser::TEMPLATE_COLUMNS;

        $matched = array_fill(0, count($columns), null);
        $this->fill($matched, $columns, [
            'ลำดับที่' => 1, 'ปี' => 2569, 'เดือน' => 'มกราคม',
            'ชื่อ' => 'สมชาย', 'นามสกุล' => 'ใจดี',
            'ID CARD' => '1000000000001', 'เลขที่บัญชี' => '1110000001',
            'เงินเดือน' => 20000, 'ยอดรวมรายรับทั้งหมด รายบุคคล' => 20000,
        ]);

        // ชื่อไม่มีในทะเบียน → เดาเลขบัตรไม่ได้ → ไม่เข้าฐานเงินสำรอง
        $unlinked = array_fill(0, count($columns), null);
        $this->fill($unlinked, $columns, [
            'ลำดับที่' => 2, 'ปี' => 2569, 'เดือน' => 'มกราคม',
            'ชื่อ' => 'ไม่มีในทะเบียน', 'นามสกุล' => 'ทดสอบ',
            'เลขที่บัญชี' => '1110000002',
            'เงินเดือน' => 35000, 'ยอดรวมรายรับทั้งหมด รายบุคคล' => 35000,
        ]);

        $path = tempnam(sys_get_temp_dir(), 'unlinked') . '.xlsx';
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getActiveSheet()->fromArray(
            array_merge([$columns], [$matched, $unlinked]),
            null,
            'A1'
        );
        (new XlsxWriter($spreadsheet))->save($path);
        $this->tempFiles[] = $path;

        return $path;
    }

    private function fill(array &$row, array $columns, array $values): void
    {
        foreach ($values as $name => $value) {
            $row[array_search($name, $columns, true)] = $value;
        }
    }

    public function test_import_reports_rows_that_cannot_join_the_registry(): void
    {
        $import = app(ImportService::class)->process(
            $this->fileWithUnlinkedRow(),
            'unlinked.xlsx',
            $this->finance->id,
            new NewFormatPayrollParser()
        );

        // ทั้ง 2 แถวถูกนำเข้าจริง
        $this->assertSame(2, $import->success_rows);

        $summary = collect($import->row_errors ?? [])->firstWhere('type', 'unlinked_summary');

        $this->assertNotNull($summary, 'ต้องรายงานยอดที่ไม่เข้าฐานเงินสำรอง');
        $this->assertSame(1, $summary['unlinked_rows']);
        $this->assertEqualsWithDelta(35000, $summary['unlinked_total_income'], 0.001);
    }

    public function test_import_response_exposes_the_unlinked_summary(): void
    {
        $path = $this->fileWithUnlinkedRow();

        // นำเข้าผ่าน service ก่อน แล้วอ่านผ่าน controller เพื่อเช็ค payload
        app(ImportService::class)->process(
            $path,
            'unlinked.xlsx',
            $this->finance->id,
            new NewFormatPayrollParser()
        );

        $controller = new \App\Http\Controllers\Api\ImportController(
            app(\App\Services\PayrollFileImportService::class)
        );

        $reflection = new \ReflectionClass($controller);
        // เรียก logic สร้าง response โดยตรงไม่ผ่าน HTTP upload (temp dir ของเซิร์ฟเวอร์เขียนไม่ได้)
        $method = $reflection->getMethod('store');
        $method->setAccessible(true);

        $request = \Illuminate\Http\Request::create('/api/imports', 'GET', [
            'uploader' => $this->finance,
        ]);
        $request->setUserResolver(fn () => $this->finance);

        // ใช้ไฟล์ที่อัปไปแล้วผ่าน UploadedFile::fake
        $request->files->set('file', \Illuminate\Http\UploadedFile::fake()->createWithContent(
            'unlinked.xlsx',
            file_get_contents($path)
        ));

        $response = $method->invoke($controller, $request);
        $payload = json_decode($response->getContent(), true);

        $this->assertArrayHasKey('unlinked_summary', $payload);
    }

    public function test_reserve_fund_shows_the_amount_that_was_excluded(): void
    {
        app(ImportService::class)->process(
            $this->fileWithUnlinkedRow(),
            'unlinked.xlsx',
            $this->finance->id,
            new NewFormatPayrollParser()
        );

        $response = $this->actingAs($this->hr)
            ->getJson('/api/hr/reserve-fund/annual?fiscal_year=2569&percent=3')
            ->assertOk();

        // นับได้แค่คนที่มีในทะเบียน
        $this->assertSame(1, $response->json('summary.total_employees'));
        $this->assertEqualsWithDelta(20000, $response->json('summary.total_income_base'), 0.001);

        // แต่ต้องเห็นว่ามีเงินอีก 35,000 ที่ถูกตัดออกไป
        $unlinked = $response->json('summary.unlinked');

        $this->assertNotNull($unlinked, 'หน้าคำนวณต้องรายงานยอดที่ถูกตัดออก');
        $this->assertSame(1, $unlinked['rows']);
        $this->assertEqualsWithDelta(35000, $unlinked['total_income'], 0.001);
    }

    public function test_no_unlinked_summary_when_everyone_is_linked(): void
    {
        $columns = NewFormatPayrollParser::TEMPLATE_COLUMNS;
        $row = array_fill(0, count($columns), null);
        $this->fill($row, $columns, [
            'ลำดับที่' => 1, 'ปี' => 2569, 'เดือน' => 'มกราคม',
            'ชื่อ' => 'สมชาย', 'นามสกุล' => 'ใจดี',
            'ID CARD' => '1000000000001', 'เลขที่บัญชี' => '1110000001',
            'เงินเดือน' => 20000, 'ยอดรวมรายรับทั้งหมด รายบุคคล' => 20000,
        ]);

        $path = tempnam(sys_get_temp_dir(), 'linked') . '.xlsx';
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getActiveSheet()->fromArray(array_merge([$columns], [$row]), null, 'A1');
        (new XlsxWriter($spreadsheet))->save($path);
        $this->tempFiles[] = $path;

        $import = app(ImportService::class)->process(
            $path,
            'linked.xlsx',
            $this->finance->id,
            new NewFormatPayrollParser()
        );

        $this->assertNull(
            collect($import->row_errors ?? [])->firstWhere('type', 'unlinked_summary'),
            'ไม่ควรมีคำเตือนเมื่อผูกกับทะเบียนได้ครบ'
        );

        $response = $this->actingAs($this->hr)
            ->getJson('/api/hr/reserve-fund/annual?fiscal_year=2569&percent=3')
            ->assertOk();

        $this->assertNull($response->json('summary.unlinked'));
    }

    public function test_same_person_across_many_periods_is_counted_once_in_the_report(): void
    {
        $columns = NewFormatPayrollParser::TEMPLATE_COLUMNS;
        $months = ['ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'];
        $rows = [];

        foreach ($months as $i => $month) {
            $row = array_fill(0, count($columns), null);
            $this->fill($row, $columns, [
                'ลำดับที่' => 1, 'ปี' => 2568, 'เดือน' => $month,
                'ชื่อ' => 'ไม่มีในทะเบียน', 'นามสกุล' => 'ทดสอบ',
                'เลขที่บัญชี' => '1110000002',
                'เงินเดือน' => 10000,
                'ยอดรวมรายรับทั้งหมด รายบุคคล' => 10000,
            ]);
            $rows[] = $row;
        }

        $path = tempnam(sys_get_temp_dir(), 'multi') . '.xlsx';
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getActiveSheet()->fromArray(array_merge([$columns], $rows), null, 'A1');
        (new XlsxWriter($spreadsheet))->save($path);
        $this->tempFiles[] = $path;

        app(ImportService::class)->process(
            $path,
            'multi.xlsx',
            $this->finance->id,
            new NewFormatPayrollParser()
        );

        $response = $this->actingAs($this->hr)
            ->getJson('/api/hr/reserve-fund/annual?fiscal_year=2569&percent=3')
            ->assertOk();

        // 3 งวด × 10,000 = 30,000 ที่ไม่เข้าฐาน
        $unlinked = $response->json('summary.unlinked');
        $this->assertSame(3, $unlinked['rows']);
        $this->assertEqualsWithDelta(30000, $unlinked['total_income'], 0.001);

        // รายการตัวอย่างต้องไม่ซ้ำคนเดียวกัน
        $this->assertCount(1, $unlinked['samples']);
    }
}
