<?php

namespace Tests\Feature;

use App\Services\Parsers\NewFormatPayrollParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * การจับคู่หัวตารางของไฟล์เงินเดือนรูปแบบใหม่
 *
 * เคยมีบั๊ก: normalizeHeader() เปลี่ยน "-" เป็นช่องว่าง แต่ COLUMN_MAP เก็บ key ดิบ
 * ทำให้คอลัมน์ "บ่าย-ดึก เงินงบประมาณ" / "บ่าย-ดึก เงินบำรุง" จับคู่ไม่ติด (ค่าเป็น 0 เสมอ)
 */
class PayrollParserHeaderMappingTest extends TestCase
{
    use RefreshDatabase;

    /** สร้างไฟล์ xlsx จากหัวตาราง TEMPLATE_COLUMNS + ข้อมูล 1 แถว */
    private function makeWorkbook(array $values): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        $columns = NewFormatPayrollParser::TEMPLATE_COLUMNS;
        $sheet->fromArray($columns, null, 'A1');

        $row = [];
        foreach ($columns as $header) {
            $row[] = $values[$header] ?? null;
        }
        $sheet->fromArray($row, null, 'A2');

        $path = tempnam(sys_get_temp_dir(), 'parser_hdr_') . '.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }

    public function test_hyphenated_night_shift_columns_are_mapped(): void
    {
        $path = $this->makeWorkbook([
            'ลำดับที่'      => 1,
            'ปี'            => 2568,
            'เดือน'         => 'ตุลาคม',
            'ชื่อ'          => 'สมชาย',
            'นามสกุล'       => 'ทดสอบ',
            'ID CARD'       => '0000000000000',
            'บ่าย-ดึก เงินงบประมาณ' => 1500,
            'บ่าย-ดึก เงินบำรุง'    => 900,
        ]);

        try {
            $records = (new NewFormatPayrollParser())->parse($path);
        } finally {
            @unlink($path);
        }

        $this->assertCount(1, $records);

        $record = $records[0];
        $this->assertSame(1500.0, (float) $record['night_shift_budget']);
        $this->assertSame(900.0, (float) $record['night_shift_maintenance']);

        // งวดยังถูกคำนวณจาก ปี + เดือน ตามปกติ (ต.ค. → ปีงบ = ปี + 1)
        $this->assertSame(2569, $record['fiscal_year']);
        $this->assertSame(10, $record['period_month']);
    }

    public function test_column_name_lookup_works_for_the_hyphenated_columns(): void
    {
        $this->assertSame('night_shift_budget', NewFormatPayrollParser::fieldForColumn('บ่าย-ดึก เงินงบประมาณ'));
        $this->assertSame('night_shift_maintenance', NewFormatPayrollParser::fieldForColumn('บ่าย-ดึก เงินบำรุง'));
    }
}
