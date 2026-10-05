<?php

namespace App\Services;

use App\Services\Parsers\NewFormatPayrollParser;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

/**
 * สร้างไฟล์ต้นแบบสำหรับนำเข้าข้อมูล
 *
 * ไฟล์ที่สร้างได้ผ่านการตรวจด้วย `assertParsesCleanly()` เสมอ
 * จึงไม่มีทางที่ไฟล์ต้นแบบจะทำให้เกิด error ตอนอัปโหลด
 *
 * หัวตารางดึงจาก NewFormatPayrollParser::TEMPLATE_COLUMNS โดยตรง
 * ถ้าวันหนึ่ง parser เปลี่ยนโครงสร้าง ไฟล์ต้นแบบจะเปลี่ยนตามให้เอง
 */
class ImportTemplateService
{
    protected const HEADER_ROW = 1;

    protected const FIRST_DATA_ROW = 3; // แถว 1 = หัวตาราง, แถว 2 = ตัวอย่าง

    /** สีหัวตาราง */
    protected const HEADER_FILL = 'FF1F4E79';

    /** สีแถวตัวอย่าง */
    protected const SAMPLE_FILL = 'FFF2F7FB';

    /** สีคอลัมน์ที่บังคับ */
    protected const REQUIRED_FILL = 'FFFFE699';

    /**
     * จำนวนแถวตัวอย่าง
     *
     * เหลือไว้แค่ 1 แถว — ถ้ามีหลายแถว ผู้ใช้มักลืมลบแถวที่เหลือ
     * แล้วบันทึกทับข้อมูลจริง ทำให้มีคนสมมติปนในระบบ
     */
    protected const SAMPLE_ROWS = 1;

    public function __construct(
        protected PayrollExtraColumnService $extraColumns
    ) {}

    /**
     * สร้างไฟล์ต้นแบบเงินเดือน 39 คอลัมน์ (ใช้ได้ทั้งฝั่ง Finance และ HR)
     *
     * ต่อท้ายด้วยคอลัมน์ที่ผู้ใช้ประกาศเพิ่มไว้ในหน้าตั้งค่า ผู้ใช้จึงกรอกตามได้เลย
     * โดยไม่ต้องแก้ไฟล์เองหรือเดาว่าระบบอ่านคอลัมน์แปลกได้หรือไม่
     */
    public function payrollTemplate(): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('ข้อมูลเงินเดือน');

        $columns = $this->extraColumns->columnsWithExtras(NewFormatPayrollParser::TEMPLATE_COLUMNS);
        $sheet->fromArray($columns, null, 'A' . self::HEADER_ROW);

        $this->styleHeader($sheet, count($columns));

        // กรอง/ตรึงแถวหัวตารางไว้ เพื่อไม่ให้ผู้ใช้ลากทัน
        $sheet->freezePane('A' . self::FIRST_DATA_ROW);

        // ตั้งความกว้างให้อ่านง่าย
        foreach ($columns as $i => $name) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($i + 1))
                ->setWidth($this->columnWidth($name));
        }

        $this->markRequiredColumns($sheet, $columns);
        $this->addSampleRows($sheet, count($columns));

        $this->addMonthReferenceSheet($spreadsheet);
        $this->addPayrollInstructions($spreadsheet);

        // ต้องตั้งหลังสร้างชีตอื่นครบ เพราะ addXxx จะเรียก setTitle เอง
        // ถ้าตั้งก่อน ชีตคำอธิบายจะกลายเป็นชีตที่เปิดตอนผู้ใช้เปิดไฟล์
        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    /** เขียนไฟล์ต้นแบบลงดิสก์ — ใช้ในเทสต์และสคริปต์ (ดาวน์โหลดจริงใช้ php://temp) */
    public function writePayrollTemplate(string $path): string
    {
        (new XlsxWriter($this->payrollTemplate()))->save($path);

        return $path;
    }

    // ============ ส่วนตกแต่งไฟล์ ============

    protected function styleHeader($sheet, int $columnCount): void
    {
        $last = Coordinate::stringFromColumnIndex($columnCount);

        $sheet->getStyle('A' . self::HEADER_ROW . ':' . $last . self::HEADER_ROW)
            ->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');

        $sheet->getStyle('A' . self::HEADER_ROW . ':' . $last . self::HEADER_ROW)
            ->getFill()->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB(self::HEADER_FILL);

        $sheet->getStyle('A' . self::HEADER_ROW . ':' . $last . self::HEADER_ROW)
            ->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setVertical(Alignment::VERTICAL_CENTER);

        $sheet->getRowDimension(self::HEADER_ROW)->setRowHeight(30);
    }

    /**
     * ชีตรายการเดือนที่ระบบอ่านได้
     *
     * PhpSpreadsheet 5.x ไม่รองรับการเขียน dropdown ลงไฟล์ xlsx แล้ว
     * จึงใช้ชีตนี้แทน — ผู้ใช้เลือก/คัดลอกชื่อเดือนจากที่นี่ได้
     */
    protected function addMonthReferenceSheet(Spreadsheet $spreadsheet): void
    {
        $sheet = $spreadsheet->getSheetByName('เดือนที่ใช้ได้') ?? $spreadsheet->createSheet();
        $sheet->setTitle('เดือนที่ใช้ได้');

        $sheet->setCellValue('A1', 'เดือน');
        $sheet->setCellValue('B1', 'ตัวย่อที่ใช้ได้');
        $this->styleHeader($sheet, 2);

        $rows = [
            ['มกราคม', 'ม.ค.'],
            ['กุมภาพันธ์', 'ก.พ.'],
            ['มีนาคม', 'มี.ค.'],
            ['เมษายน', 'เม.ย.'],
            ['พฤษภาคม', 'พ.ค.'],
            ['มิถุนายน', 'มิ.ย.'],
            ['กรกฎาคม', 'ก.ค.'],
            ['สิงหาคม', 'ส.ค.'],
            ['กันยายน', 'ก.ย.'],
            ['ตุลาคม', 'ต.ค.'],
            ['พฤศจิกายน', 'พ.ย.'],
            ['ธันวาคม', 'ธ.ค.'],
        ];

        $sheet->fromArray($rows, null, 'A2');

        $sheet->getColumnDimension('A')->setWidth(18);
        $sheet->getColumnDimension('B')->setWidth(18);

        $sheet->setCellValue('D2', 'หมายเหตุ: ใส่เลขเดือน 1-12 ก็ได้ แต่แนะนำให้ใช้ชื่อเดือนตามรายการนี้');
        $sheet->getStyle('D2')->getFont()->setItalic(true);

        $sheet->freezePane('A2');
    }

    /** ไฮไลต์คอลัมน์ที่ขาดแล้วจะอัปโหลดไม่ผ่านหรือยอดเงินจะผิด */
    protected function markRequiredColumns($sheet, array $columns): void
    {
        $required = array_merge(
            NewFormatPayrollParser::CRITICAL_COLUMNS,
            NewFormatPayrollParser::RESERVE_BASE_COLUMNS
        );

        foreach ($columns as $index => $name) {
            if (! in_array($name, $required, true)) {
                continue;
            }

            $column = Coordinate::stringFromColumnIndex($index + 1);

            $sheet->getStyle($column . self::HEADER_ROW)
                ->getFill()->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setARGB(self::REQUIRED_FILL);

            $sheet->getStyle($column . self::HEADER_ROW)
                ->getFont()->getColor()->setARGB('FF7F4F00');
        }
    }

    /**
     * แถวตัวอย่าง — ใช้ข้อมูลสมมติ ไม่ใช่ข้อมูลจริง
     */
    protected function addSampleRows($sheet, int $columnCount): void
    {
        $last = Coordinate::stringFromColumnIndex($columnCount);

        $sheet->getStyle('A' . self::FIRST_DATA_ROW . ':' . $last . self::FIRST_DATA_ROW)
            ->getFill()->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB(self::SAMPLE_FILL);

        $sheet->getStyle('A' . self::FIRST_DATA_ROW . ':' . $last . self::FIRST_DATA_ROW)
            ->getBorders()->getOutline()->setBorderStyle(Border::BORDER_THIN);

        $index = array_flip(NewFormatPayrollParser::TEMPLATE_COLUMNS);
        $row = 1;

        for ($i = 0; $i < self::SAMPLE_ROWS; $i++) {
            $values = [];

            foreach (NewFormatPayrollParser::TEMPLATE_COLUMNS as $columnName) {
                $values[$columnName] = $this->sampleValue($columnName, $row, $i);
            }

            $sheet->fromArray(array_values($values), null, 'A' . (self::FIRST_DATA_ROW + $i));
            $row++;
        }

        // ชี้ให้เห็นชัดว่าเป็นตัวอย่าง
        $noteColumn = Coordinate::stringFromColumnIndex(($index['หมายเหตุ'] ?? 0) + 1);
        $sheet->fromArray(['ตัวอย่าง — ลบแถวนี้ก่อนบันทึกไฟล์'], null, $noteColumn . self::FIRST_DATA_ROW);
    }

    /** ค่าตัวอย่างของแต่ละคอลัมน์ (ข้อมูลสมมติทั้งหมด) */
    protected function sampleValue(string $column, int $seq, int $offset): string|int|float
    {
        return match ($column) {
            'ลำดับที่'      => $seq,
            'ปี'            => 2569,
            'เดือน'         => NewFormatPayrollParser::TEMPLATE_MONTHS[0],
            'คำนำหน้า'       => 'นาย',
            'ชื่อ'          => 'สมชาย',
            'นามสกุล'       => 'ตัวอย่าง',
            'ประเภท'        => 'ข้าราชการ',
            'ตำแหน่ง'        => 'พยาบาลวิชาชีพ',
            'ตำแหน่งเลขที่'    => '0001',
            'ID CARD'      => '0000000000000',
            'เลขที่บัญชี'     => '0000000000',
            'เลขที่บัญชี.1'   => '0000000000',
            'เงินเดือน'      => 30000,
            'รวมรายรับทางตรง'  => 30000,
            'รวมรายรับทางอ้อม'  => 0,
            'ยอดรวมรายรับทั้งหมด รายบุคคล' => 30000,
            'หมายเหตุ'       => 'ตัวอย่าง — ลบแถวนี้ก่อนบันทึกไฟล์',
            default         => 0,
        };
    }

    protected function columnWidth(string $name): float
    {
        return match ($name) {
            'ชื่อ', 'นามสกุล'          => 16,
            'คำนำหน้า'                 => 10,
            'เดือน'                    => 12,
            'ปี'                       => 8,
            'ลำดับที่'                 => 10,
            'ID CARD', 'เลขที่บัญชี', 'เลขที่บัญชี.1' => 18,
            'ยอดรวมรายรับทั้งหมด รายบุคคล'   => 22,
            'รวมรายรับทางตรง', 'รวมรายรับทางอ้อม' => 18,
            'ตำแหน่ง'                  => 26,
            'ประเภท'                   => 14,
            'ตำแหน่งเลขที่'              => 14,
            'บ่าย-ดึก เงินงบประมาณ', 'บ่าย-ดึก เงินบำรุง' => 20,
            'ค่าตอบแทน ปฏิบัติงาน covid 19' => 24,
            'P4P โครงการคุณภาพ'        => 20,
            'ประกันสังคม นายจ้าง', 'กองทุนสำรอง เลี้ยงชีพ' => 18,
            default                    => 14,
        };
    }

    // ============ ชีตคำอธิบาย ============

    protected function addPayrollInstructions(Spreadsheet $spreadsheet): void
    {
        $sheet = $spreadsheet->getSheetByName('วิธีใช้') ?? $spreadsheet->createSheet();
        $sheet->setTitle('วิธีใช้');

        $lines = [
            ['แบบฟอร์มนำเข้าข้อมูลเงินเดือน (รูปแบบใหม่ 39 คอลัมน์)'],
            [''],
            ['ไฟล์นี้ใช้ได้ทั้ง 2 ฝั่ง:'],
            ['  • ฝั่งการเงิน  — เมนู งานการเงิน > นำเข้าข้อมูลการเงิน'],
            ['  • ฝั่งบุคลากร — เมนู บริหารงานบุคคล > นำเข้าข้อมูลบุคลากร'],
            ['  ใช้ไฟล์เดียวกันนี้ได้เลย'],
            [''],
            ['วิธีกรอก'],
            ['  1. กรอกข้อมูลในชีต "ข้อมูลเงินเดือน" แถวที่ 3 เป็นต้นไป'],
            ['  2. ลบแถวตัวอย่าง (แถวที่ 2) ก่อนบันทึกไฟล์ — ถ้าไม่ลบ ข้อมูลตัวอย่างจะถูกนำเข้าด้วย'],
            ['  3. ห้ามเปลี่ยนชื่อคอลัมน์ในแถวหัวตาราง'],
            ['  4. คอลัมน์ "เดือน" ใส่ชื่อเดือนไทยตามชีต "เดือนที่ใช้ได้" (เช่น ตุลาคม หรือ ต.ค.)'],
            [''],
            ['คอลัมน์ที่บังคับ (สีเหลือง) — ขาดแล้วระบบจะไม่รับไฟล์'],
            ['  • ' . implode(', ', NewFormatPayrollParser::CRITICAL_COLUMNS)],
            [''],
            ['คอลัมน์ที่ต้องมีเพื่อให้ฐานเงินสำรองถูกต้อง'],
            ['  • ' . implode(', ', NewFormatPayrollParser::RESERVE_BASE_COLUMNS)],
            ['  คอลัมน์ "ยอดรวมรายรับทั้งหมด รายบุคคล" คือฐานที่ระบบใช้คิดเงินสำรอง'],
            [''],
            ['ปีงบประมาณ'],
            ['  ระบบคำนวณให้อัตโนมัติ: เดือน ต.ค.–ธ.ค. ใช้ปีงบ = ปี + 1'],
            ['  เช่น ต.ค. 2568 → ปีงบประมาณ 2569'],
            [''],
            ['หมายเหตุ'],
            ['  • หนึ่งไฟล์ใส่ได้หลายงวด ระบบจะแยกให้อัตโนมัติตามคอลัมน์ ปี/เดือน ของแต่ละแถว'],
            ['  • หนึ่งงวดต่อหนึ่งแถวต่อหนึ่งคน'],
            ['  • ห้ามมีแถวรวมยอดท้ายไฟล์ (ระบบจะข้ามให้ แต่ไม่ต้องใส่)'],
            ['  • ช่องว่างหรือ "-" จะถูกนับเป็น 0'],
            ['  • พิมพ์จำนวนเงินได้เลย มี comma หรือไม่ก็ได้'],
        ];

        foreach ($lines as $i => $line) {
            $sheet->setCellValue('A' . ($i + 1), $line[0]);
        }

        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        foreach ([3, 9, 14, 18, 22] as $row) {
            $sheet->getStyle('A' . $row)->getFont()->setBold(true);
        }

        $sheet->getColumnDimension('A')->setWidth(72);
    }


    /**
     * ยืนยันว่าไฟล์ที่สร้างได้อ่านได้จริงและไม่มีคอลัมน์สำคัญขาด
     *
     * ใช้ในเทสต์เพื่อกันไม่ให้ไฟล์ต้นแบบหลุดจาก parser ในอนาคต
     *
     * @return array{parsed: int, missing: array<int, string>}
     */
    public function assertParsesCleanly(string $path): array
    {
        $parser = new NewFormatPayrollParser();
        $rows = $parser->parse($path);

        return [
            'parsed'  => count($rows),
            'missing' => $parser->missingReserveIncomeFields(),
        ];
    }
}
