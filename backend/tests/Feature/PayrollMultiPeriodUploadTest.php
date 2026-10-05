<?php

namespace Tests\Feature;

use App\Models\Duty;
use App\Models\Employee;
use App\Models\EmployeeStatus;
use App\Models\Group;
use App\Models\Import;
use App\Models\Payroll;
use App\Models\User;
use App\Models\Work;
use App\Services\ImportService;
use App\Services\Parsers\NewFormatPayrollParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Tests\TestCase;

/**
 * อัปโหลด payroll ได้ทั้งสองแบบ
 *
 * 1) ไฟล์หนึ่งเดือน   — แถวทุกแถวเป็นงวดเดียวกัน
 * 2) ไฟล์หลายเดือน  — แต่ละแถวมีคอลัมน์ ปี/เดือน ของตัวเอง
 *
 * ทั้งสองแบบต้องบันทึกลงฐานข้อมูลถูกงวด และหน้าเงินสำรองต้องแยกรายงวดได้
 */
class PayrollMultiPeriodUploadTest extends TestCase
{
    use RefreshDatabase;

    private array $tempFiles = [];

    private User $user;

    /** ผู้ใช้สำหรับเรียก API เงินสำรอง (หน้านี้อยู่ฝั่ง HR) */
    private User $hrUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'name'     => 'Finance Upload',
            'username' => 'finance_upload',
            'email'    => 'finance_upload@example.test',
            'password' => bcrypt('secret-password'),
            'role'     => 'finance',
        ]);

        $this->hrUser = User::create([
            'name'     => 'HR Upload',
            'username' => 'hr_upload',
            'email'    => 'hr_upload@example.test',
            'password' => bcrypt('secret-password'),
            'role'     => 'hr',
        ]);

        $duty = Duty::create(['name' => 'ด้านการพยาบาล']);
        $group = Group::create(['name' => 'กลุ่มงานการพยาบาล', 'duty_id' => $duty->id]);
        $work = Work::create(['name' => 'งานผู้ป่วยนอก', 'group_id' => $group->id]);
        $working = EmployeeStatus::create(['name' => 'ปฏิบัติงานอยู่', 'sort_order' => 1]);

        // คนที่อยู่ในไฟล์ 2 คน — ไฟล์รูปแบบใหม่ไม่มีคอลัมน์เลขบัตรประชาชน
        // ระบบจึงต้องจับคู่ด้วยชื่อ-นามสกุล
        foreach ([['สมชาย', 'ใจดี'], ['สมหญิง', 'รักดี']] as $i => [$first, $last]) {
            Employee::create([
                'citizen_id' => '111111111111' . $i,
                'first_name' => $first,
                'last_name'  => $last,
                'duty_id'    => $duty->id,
                'group_id'   => $group->id,
                'work_id'    => $work->id,
                'status_id'  => $working->id,
            ]);
        }
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

    /** header 39 คอลัมน์ของไฟล์รูปแบบใหม่ */
    private function newFormatHeader(): array
    {
        return [
            'ลำดับที่', 'ปี', 'เดือน', 'คำนำหน้า', 'ชื่อ', 'นามสกุล', 'ประเภท',
            'ตำแหน่ง', 'ตำแหน่งเลขที่', 'ID CARD', 'เลขที่บัญชี', 'เลขที่บัญชี.1',
            'เงินเดือน', 'ตกเบิก', 'ง.บ.ส.ก.', 'ปจต.', 'ค่าครองชีพ', 'ไม่ทำเวชฯ',
            'พตส.', 'ค่าOT', 'บ่าย-ดึก เงินงบประมาณ', 'บ่าย-ดึก เงินบำรุง',
            'P4P ประจำเดือน', 'ค่าตอบแทน ปฏิบัติงาน covid 19', 'P4P โครงการคุณภาพ',
            'รายได้อื่น', 'รวมรายรับทางตรง',
            'ค่ารักษา', 'ค่าเล่าเรียน', 'ค่าเบี้ยเลี้ยง', 'ค่าเช่าที่พัก', 'ค่าพาหนะ',
            'ค่าใช้จ่ายอื่น ๆ', 'ต้นทุนจัดโครงการ',
            'ประกันสังคม นายจ้าง', 'กองทุนสำรอง เลี้ยงชีพ', 'รวมรายรับทางอ้อม',
            'ยอดรวมรายรับทั้งหมด รายบุคคล', 'หมายเหตุ',
        ];
    }

    /**
     * แถวคนหนึ่งคนหนึ่งงวด
     *
     * @param  array{ปี:int, เดือน:string}  $period
     */
    private function staffRow(int $seq, array $period, string $first, string $last, float $salary): array
    {
        $total = $salary;

        return [
            $seq, $period['ปี'], $period['เดือน'], 'นาย', $first, $last, 'ข้าราชการ',
            'พยาบาลชั้นสูง', '4415', '', '536004153', '536004153',
            ' ' . number_format($salary, 2, '.', ',') . ' ',
            null, null, '0.00', '0.00', null, '0.00', '0.00', null, null,
            '0.00', null, null, '0.00', number_format($total, 2, '.', ','),
            null, null, null, null, null, null, null,
            '0.00', '0.00', '0.00', number_format($total, 2, '.', ','), null,
        ];
    }

    /** แถวรวมยอดท้ายงวด — ต้องไม่ถูกนับเป็นบุคลากร */
    private function totalRow(array $period, float $sum): array
    {
        $row = array_fill(0, count($this->newFormatHeader()), null);
        $row[1] = $period['ปี'];
        $row[2] = $period['เดือน'];
        $row[12] = number_format($sum, 2, '.', ',');
        $row[25] = number_format($sum, 2, '.', ',');
        $row[36] = number_format($sum, 2, '.', ',');

        return $row;
    }

    private function writeNewFormatFile(array $rows): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray(array_merge([$this->newFormatHeader()], $rows), null, 'A1');

        $path = tempnam(sys_get_temp_dir(), 'payroll') . '.xlsx';
        (new XlsxWriter($spreadsheet))->save($path);
        $this->tempFiles[] = $path;

        return $path;
    }

    private function import(string $path, ?array $period = null): Import
    {
        return app(ImportService::class)->process(
            $path,
            'payroll.xlsx',
            $this->user->id,
            new NewFormatPayrollParser(),
            $period
        );
    }

    protected function annual(int $fiscalYear = 2569)
    {
        return $this->actingAs($this->hrUser)
            ->getJson("/api/hr/reserve-fund/annual?fiscal_year={$fiscalYear}&percent=3");
    }

    public function test_single_month_file_is_imported_and_lands_in_one_period(): void
    {
        $path = $this->writeNewFormatFile([
            $this->staffRow(1, ['ปี' => 2569, 'เดือน' => 'มกราคม'], 'สมชาย', 'ใจดี', 20000),
            $this->staffRow(2, ['ปี' => 2569, 'เดือน' => 'มกราคม'], 'สมหญิง', 'รักดี', 18000),
            $this->totalRow(['ปี' => 2569, 'เดือน' => 'มกราคม'], 38000),
        ]);

        $import = $this->import($path);

        // แถวรวมยอดถูกตัดออกตั้งแต่ขั้น parse จึงไม่ถูกนับเป็นคนที่ 3
        $this->assertSame(2, $import->total_rows);
        $this->assertSame(2, $import->success_rows);
        $this->assertSame(0, $import->skipped_rows);

        $this->assertSame(
            1,
            Payroll::where('import_id', $import->id)->distinct()->count('period_month')
        );

        $response = $this->annual()->assertOk();
        $this->assertSame(1, $response->json('summary.months_present'));
        $this->assertEqualsWithDelta(38000, $response->json('summary.total_income_base'), 0.001);
        $this->assertSame(2, $response->json('summary.total_employees'));
    }

    public function test_single_month_file_works_when_period_is_supplied_by_the_user(): void
    {
        // หน้าเว็บส่ง period_month มาทุกครั้ง — ต้องไม่ทำให้ข้อมูลเพี้ยน
        $path = $this->writeNewFormatFile([
            $this->staffRow(1, ['ปี' => 2569, 'เดือน' => 'มกราคม'], 'สมชาย', 'ใจดี', 20000),
            $this->totalRow(['ปี' => 2569, 'เดือน' => 'มกราคม'], 20000),
        ]);

        $import = $this->import($path, [
            'fiscal_year'  => 2569,
            'period_month' => 1,
            'period_year'  => 2569,
        ]);

        $this->assertSame(1, $import->success_rows);

        $response = $this->annual()->assertOk();
        $this->assertEqualsWithDelta(20000, $response->json('summary.total_income_base'), 0.001);
        $this->assertSame(1, $response->json('summary.total_employees'));
    }

    public function test_multi_month_file_keeps_each_row_its_own_period(): void
    {
        $path = $this->writeNewFormatFile([
            $this->staffRow(1, ['ปี' => 2568, 'เดือน' => 'ตุลาคม'], 'สมชาย', 'ใจดี', 20000),
            $this->staffRow(2, ['ปี' => 2568, 'เดือน' => 'ตุลาคม'], 'สมหญิง', 'รักดี', 18000),
            $this->totalRow(['ปี' => 2568, 'เดือน' => 'ตุลาคม'], 38000),
            $this->staffRow(3, ['ปี' => 2568, 'เดือน' => 'พฤศจิกายน'], 'สมชาย', 'ใจดี', 20000),
            $this->staffRow(4, ['ปี' => 2568, 'เดือน' => 'พฤศจิกายน'], 'สมหญิง', 'รักดี', 18000),
            $this->totalRow(['ปี' => 2568, 'เดือน' => 'พฤศจิกายน'], 38000),
            $this->staffRow(5, ['ปี' => 2568, 'เดือน' => 'ธันวาคม'], 'สมชาย', 'ใจดี', 20000),
            $this->staffRow(6, ['ปี' => 2568, 'เดือน' => 'ธันวาคม'], 'สมหญิง', 'รักดี', 18000),
            $this->totalRow(['ปี' => 2568, 'เดือน' => 'ธันวาคม'], 38000),
        ]);

        $import = $this->import($path);

        // 6 คน-งวด เข้าไปครบ (แถวรวมยอด 3 แถวถูกตัดตอน parse)
        $this->assertSame(6, $import->total_rows);
        $this->assertSame(6, $import->success_rows);
        $this->assertSame(0, $import->skipped_rows);

        // แต่ละแถวต้องเก็บงวดของตัวเอง ไม่ใช่งวดเดียวกันทั้งไฟล์
        $this->assertSame(
            [10, 11, 12],
            Payroll::where('import_id', $import->id)
                ->distinct()
                ->orderBy('period_month')
                ->pluck('period_month')
                ->map(fn ($m) => (int) $m)
                ->all()
        );
    }

    public function test_multi_month_file_is_split_into_separate_periods_in_reserve_fund(): void
    {
        $path = $this->writeNewFormatFile([
            $this->staffRow(1, ['ปี' => 2568, 'เดือน' => 'ตุลาคม'], 'สมชาย', 'ใจดี', 20000),
            $this->staffRow(2, ['ปี' => 2568, 'เดือน' => 'ตุลาคม'], 'สมหญิง', 'รักดี', 18000),
            $this->totalRow(['ปี' => 2568, 'เดือน' => 'ตุลาคม'], 38000),
            $this->staffRow(3, ['ปี' => 2568, 'เดือน' => 'พฤศจิกายน'], 'สมชาย', 'ใจดี', 20000),
            $this->staffRow(4, ['ปี' => 2568, 'เดือน' => 'พฤศจิกายน'], 'สมหญิง', 'รักดี', 18000),
            $this->totalRow(['ปี' => 2568, 'เดือน' => 'พฤศจิกายน'], 38000),
            $this->staffRow(5, ['ปี' => 2568, 'เดือน' => 'ธันวาคม'], 'สมชาย', 'ใจดี', 30000),
            $this->staffRow(6, ['ปี' => 2568, 'เดือน' => 'ธันวาคม'], 'สมหญิง', 'รักดี', 18000),
            $this->totalRow(['ปี' => 2568, 'เดือน' => 'ธันวาคม'], 48000),
        ]);

        $this->import($path);

        $response = $this->annual()->assertOk();

        $this->assertSame(3, $response->json('summary.months_present'));

        $months = collect($response->json('summary.months'))->keyBy('period_month');

        $this->assertEqualsWithDelta(38000, $months[10]['income_base'], 0.001);
        $this->assertEqualsWithDelta(38000, $months[11]['income_base'], 0.001);
        $this->assertEqualsWithDelta(48000, $months[12]['income_base'], 0.001);

        // คนเดิม 2 คน 3 งวด → ยังนับเป็น 2 คน ไม่ใช่ 6
        foreach ([10, 11, 12] as $month) {
            $this->assertSame(2, $months[$month]['total_employees']);
        }
        $this->assertSame(2, $response->json('summary.total_employees'));

        $this->assertEqualsWithDelta(124000, $response->json('summary.total_income_base'), 0.001);
    }

    public function test_multi_month_file_is_split_even_when_a_period_is_supplied(): void
    {
        // ผู้ใช้เลือกงวดในหน้าเว็บ แต่ไฟล์มีหลายงวด — งวดจริงในแถวต้องชนะ
        $path = $this->writeNewFormatFile([
            $this->staffRow(1, ['ปี' => 2568, 'เดือน' => 'ตุลาคม'], 'สมชาย', 'ใจดี', 20000),
            $this->totalRow(['ปี' => 2568, 'เดือน' => 'ตุลาคม'], 20000),
            $this->staffRow(2, ['ปี' => 2568, 'เดือน' => 'พฤศจิกายน'], 'สมชาย', 'ใจดี', 25000),
            $this->totalRow(['ปี' => 2568, 'เดือน' => 'พฤศจิกายน'], 25000),
        ]);

        $this->import($path, [
            'fiscal_year'  => 2569,
            'period_month' => 1,
            'period_year'  => 2569,
        ]);

        $response = $this->annual()->assertOk();

        $this->assertSame(2, $response->json('summary.months_present'));

        $months = collect($response->json('summary.months'))->keyBy('period_month');
        $this->assertEqualsWithDelta(20000, $months[10]['income_base'], 0.001);
        $this->assertEqualsWithDelta(25000, $months[11]['income_base'], 0.001);

        // ม.ค. ไม่ควรมีข้อมูล เพราะไฟล์ไม่มีงวดมกราคม
        $this->assertFalse($months[1]['has_data']);
    }
}
