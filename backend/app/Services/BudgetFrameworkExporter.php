<?php

namespace App\Services;

use App\Support\ProfessionalGroupCatalog;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * ส่งออก "กรอบวงเงิน P4P" เป็นไฟล์ Excel ตามรูปแบบเอกสารต้นแบบ
 *
 * ใช้ phpoffice/phpspreadsheet ที่ project มีอยู่แล้ว (ตัวเดียวกับ parser/exporter อื่น)
 * payload ที่รับเข้ามาคือผลของ BudgetFrameworkService::build() หรือ ::snapshot()
 */
class BudgetFrameworkExporter
{
    /** คอลัมน์ A–J: ลำดับ | กลุ่มวิชาชีพ | ชื่อกลุ่ม | จำนวนคน | สัดส่วน | รวมสัดส่วน | ยอดปี | ยอดเดือน | เฉลี่ยปี | เฉลี่ยเดือน */
    protected const LAST_COLUMN = 'J';

    protected const WIDTHS = [
        'A' => 7, 'B' => 40, 'C' => 32, 'D' => 12,
        'E' => 12, 'F' => 15, 'G' => 17, 'H' => 18,
        'I' => 16, 'J' => 18,
    ];

    protected const MONEY_FORMAT = '#,##0.00';

    protected const WEIGHT_FORMAT = '0.00';

    /**
     * @param  array<string, mixed>  $payload
     */
    public function download(array $payload): BinaryFileResponse
    {
        $spreadsheet = $this->build($payload);

        $fileName = sprintf('budget-framework-FY%d.xlsx', (int) $payload['fiscal_year']);

        // ไม่ใช้ tempnam()/sys_get_temp_dir() เพราะบางเครื่อง temp dir ของระบบเขียนไม่ได้
        $tempDir = storage_path('app/tmp');
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0775, true);
        }

        $tempPath = $tempDir.DIRECTORY_SEPARATOR.'bf_'.uniqid('', true).'.xlsx';

        (new Xlsx($spreadsheet))->save($tempPath);
        $spreadsheet->disconnectWorksheets();

        return response()
            ->download($tempPath, $fileName, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])
            ->deleteFileAfterSend(true);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function build(array $payload): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('กรอบวงเงิน P4P');

        foreach (self::WIDTHS as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }

        $last = self::LAST_COLUMN;

        // ===== หัวเรื่อง =====
        $row = 1;
        $title = sprintf(
            'กรอบวงเงิน P4P ของโรงพยาบาลประสาทเชียงใหม่ ปีงบประมาณ พ.ศ. %d',
            (int) $payload['fiscal_year']
        );
        $this->setTextCell($sheet, "A{$row}", $title);
        $sheet->mergeCells("A{$row}:{$last}{$row}");
        $sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle("A{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $row += 2;

        // ===== บล็อกสรุปยอด =====
        $summary = [
            ['ค่าแรง (Labor cost) ณ '.($payload['as_of_label'] ?? '-'), $payload['labor_cost']['to_date'], self::MONEY_FORMAT],
            ['ค่าแรง (Labor cost) ต่อปี', $payload['labor_cost']['annual'], self::MONEY_FORMAT],
            ['ค่าแรง (Labor cost) ต่อเดือน', $payload['labor_cost']['monthly'], self::MONEY_FORMAT],
            ['ร้อยละของค่าแรง', null, null, $this->formatPercent($payload['labor_percent']).'%'],
            ['จ่าย P4P ต่อปี', $payload['p4p_annual'], self::MONEY_FORMAT],
            [
                '  - วงเงินตามปริมาณงาน (Activity) ('.$this->formatPercent($payload['activity_ratio']).'%)',
                $payload['activity_budget'],
                self::MONEY_FORMAT,
            ],
            [
                '  - วงเงินเพื่อการพัฒนาคุณภาพ (Quality) ('.$this->formatPercent($payload['quality_ratio']).'%)',
                $payload['quality_budget'],
                self::MONEY_FORMAT,
            ],
        ];

        foreach ($summary as $line) {
            $label = $line[0];
            $value = $line[1] ?? null;
            $format = $line[2] ?? null;
            $text = $line[3] ?? null;

            $this->setTextCell($sheet, "A{$row}", $label);
            $sheet->mergeCells("A{$row}:D{$row}");

            if ($text !== null) {
                $this->setTextCell($sheet, "E{$row}", $text);
            } elseif ($value !== null) {
                $sheet->setCellValue("E{$row}", round((float) $value, 2));
                $sheet->getStyle("E{$row}")->getNumberFormat()->setFormatCode((string) $format);
            }

            $sheet->getStyle("E{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $row++;
        }

        $row++;

        // ===== หัวตาราง =====
        $this->setTextCell($sheet, "A{$row}", 'ประมาณการ อัตราค่าตอบแทนต่อคน ตามสัดส่วนวิชาชีพ');
        $sheet->mergeCells("A{$row}:{$last}{$row}");
        $sheet->getStyle("A{$row}")->getFont()->setBold(true);
        $sheet->getStyle("A{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $row++;

        $headerRow = $row;

        $this->setTextCell($sheet, "A{$headerRow}", 'กลุ่มวิชาชีพ');
        $sheet->mergeCells("A{$headerRow}:C{$headerRow}");
        $sheet->getStyle("A{$headerRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $headers = [
            'D' => 'จำนวนคน (คน)',
            'E' => 'สัดส่วน (คน)',
            'F' => 'รวมสัดส่วน (คน*คน)',
            'G' => 'รวมเงินวิชาชีพต่อปี',
            'H' => 'รวมเงินวิชาชีพต่อเดือน',
            'I' => 'เฉลี่ย/คน (ต่อปี)',
            'J' => 'เฉลี่ย/คน (ต่อเดือน)',
        ];

        foreach ($headers as $column => $label) {
            $this->setTextCell($sheet, "{$column}{$headerRow}", $label);
        }

        $headerStyle = $sheet->getStyle("A{$headerRow}:{$last}{$headerRow}");
        $headerStyle->getFont()->setBold(true);
        $headerStyle->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setVertical(Alignment::VERTICAL_CENTER)
            ->setWrapText(true);

        // ไฮไลต์หัวคอลัมน์ "สัดส่วน" สีเหลือง ตามเอกสารต้นแบบ
        $sheet->getStyle("E{$headerRow}")->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('FFFF00');

        // ===== แถวข้อมูลแต่ละกลุ่มวิชาชีพ =====
        $row++;
        $firstDataRow = $row;

        foreach (array_values($payload['groups'] ?? []) as $index => $group) {
            $description = $group['name'];
            if (! empty($group['examples'])) {
                $description .= ' เป็น '.$group['examples'];
            }

            $sheet->setCellValue("A{$row}", $index + 1);

            $this->setTextCell($sheet, "B{$row}", $description);
            $sheet->getStyle("B{$row}")->getAlignment()->setWrapText(true);

            $this->setTextCell($sheet, "C{$row}", $group['short_name'] ?? '');
            $sheet->getStyle("C{$row}")->getFont()->setBold(true);

            $sheet->setCellValue("D{$row}", (int) $group['headcount']);
            $sheet->setCellValue("E{$row}", round((float) $group['weight'], 2));
            $sheet->setCellValue("F{$row}", round((float) $group['weighted'], 2));
            $sheet->setCellValue("G{$row}", round((float) $group['amount_year'], 2));
            $sheet->setCellValue("H{$row}", round((float) $group['amount_month'], 2));
            $sheet->setCellValue("I{$row}", round((float) $group['avg_year'], 2));
            $sheet->setCellValue("J{$row}", round((float) $group['avg_month'], 2));

            $row++;
        }

        $lastDataRow = $row - 1;

        // ===== แถวรวม =====
        $total = $payload['total'];

        $this->setTextCell($sheet, "A{$row}", 'รวม');
        $sheet->mergeCells("A{$row}:C{$row}");
        $sheet->getStyle("A{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $sheet->setCellValue("D{$row}", (int) $total['headcount']);
        $sheet->setCellValue("E{$row}", round((float) $total['weight'], 2));
        $sheet->setCellValue("F{$row}", round((float) $total['weighted'], 2));
        $sheet->setCellValue("G{$row}", round((float) $total['amount_year'], 2));
        $sheet->setCellValue("H{$row}", round((float) $total['amount_month'], 2));
        $sheet->setCellValue("I{$row}", round((float) $total['avg_year'], 2));
        $sheet->setCellValue("J{$row}", round((float) $total['avg_month'], 2));

        $sheet->getStyle("A{$row}:{$last}{$row}")->getFont()->setBold(true);

        $totalRow = $row;

        // จำนวนเงิน + สัดส่วน (ทศนิยม 2 ตำแหน่ง)
        $sheet->getStyle("E{$firstDataRow}:J{$totalRow}")
            ->getNumberFormat()->setFormatCode(self::MONEY_FORMAT);
        $sheet->getStyle("E{$firstDataRow}:F{$totalRow}")
            ->getNumberFormat()->setFormatCode(self::WEIGHT_FORMAT);

        // ขอบตาราง
        $sheet->getStyle("A{$headerRow}:{$last}{$totalRow}")
            ->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

        // ===== เงิน P4P ต่อหน่วย (KPI) — คอลัมน์เดียวกับ "รวมสัดส่วน" ตามเอกสารต้นแบบ =====
        $row = $totalRow + 1;
        $this->setTextCell($sheet, "A{$row}", 'รวมเงิน P4P ต่อหน่วย (KPI)');
        $sheet->mergeCells("A{$row}:E{$row}");
        $sheet->getStyle("A{$row}")->getFont()->setBold(true);
        $sheet->setCellValue("F{$row}", round((float) $payload['unit_rate_month'], 2));
        $sheet->getStyle("F{$row}")->getNumberFormat()->setFormatCode(self::MONEY_FORMAT);
        $row += 2;

        // ===== บล็อกสรุปการแบ่งเงิน 70/30 =====
        $this->setTextCell($sheet, "A{$row}", 'แบ่งสัดส่วนเงินสำหรับจ่ายค่าตอบแทนตามผลการปฏิบัติงานต่อเดือนออกเป็น 2 ส่วน คือ');
        $sheet->mergeCells("A{$row}:{$last}{$row}");
        $row++;

        $this->writeAmountLine(
            $sheet,
            $row++,
            '1. ร้อยละ '.$this->formatPercent($payload['quality_ratio']).' เพื่อการพัฒนาคุณภาพบริการ',
            (float) $payload['quality_budget']
        );
        $this->writeAmountLine(
            $sheet,
            $row++,
            '2. ร้อยละ '.$this->formatPercent($payload['activity_ratio']).' เพื่อบริหารจัดการและวิชาชีพ',
            (float) $payload['activity_budget']
        );
        $this->writeAmountLine($sheet, $row, 'รวมเงิน P4P', (float) $payload['p4p_annual']);

        return $spreadsheet;
    }

    /** บรรทัด "label ......... เป็นเงิน X บาท" */
    protected function writeAmountLine($sheet, int $row, string $label, float $amount): void
    {
        $this->setTextCell($sheet, "A{$row}", $label);
        $sheet->mergeCells("A{$row}:C{$row}");

        $this->setTextCell($sheet, "D{$row}", 'เป็นเงิน');
        $sheet->setCellValue("E{$row}", round($amount, 2));
        $sheet->getStyle("E{$row}")->getNumberFormat()->setFormatCode(self::MONEY_FORMAT);
        $this->setTextCell($sheet, "F{$row}", 'บาท');
    }

    /**
     * เขียนค่าที่มาจากผู้ใช้เป็นข้อความธรรมดา (กัน Excel Formula Injection)
     */
    protected function setTextCell($sheet, string $coordinate, ?string $value): void
    {
        $sheet->setCellValueExplicit($coordinate, (string) $value, DataType::TYPE_STRING);
    }

    /** แสดงเปอร์เซ็นต์แบบไม่มีทศนิยมเกินจำเป็น เช่น 3, 3.5, 70 */
    protected function formatPercent(mixed $value): string
    {
        $number = (float) $value;

        return rtrim(rtrim(number_format($number, 2, '.', ''), '0'), '.') ?: '0';
    }

    /** รายชื่อกลุ่มวิชาชีพตามลำดับเอกสาร (ใช้ทดสอบ/ตรวจสอบ) */
    public static function orderedGroupCodes(): array
    {
        return ProfessionalGroupCatalog::codes();
    }
}
