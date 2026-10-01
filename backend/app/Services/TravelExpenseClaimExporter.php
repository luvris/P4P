<?php

namespace App\Services;

use App\Models\TravelExpenseClaim;
use App\Support\ThaiFiscalYear;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * ส่งออกใบเบิกค่าใช้จ่ายเดินทางไปราชการเป็น Excel
 *
 * ใช้ phpoffice/phpspreadsheet ที่ project มีอยู่แล้ว (ตัวเดียวกับ XlsxParser)
 */
class TravelExpenseClaimExporter
{
    /** คอลัมน์ A–H ตามแบบฟอร์มอ้างอิง */
    private const HEADERS = [
        'ลำดับ', 'รายชื่อ', 'นามสกุล', 'ค่าเบี้ยเลี้ยง',
        'ค่าที่พัก', 'ค่าพาหนะ', 'ค่าใช้จ่ายอื่น', 'รวมเงิน',
    ];

    private const WIDTHS = [
        'A' => 8, 'B' => 22, 'C' => 22, 'D' => 14,
        'E' => 14, 'F' => 14, 'G' => 16, 'H' => 16,
    ];

    /** 0 แสดงเป็น "-" ให้ตรงกับแบบฟอร์ม */
    private const MONEY_FORMAT = '#,##0.00;-#,##0.00;"-"';

    public function download(TravelExpenseClaim $claim): BinaryFileResponse
    {
        $spreadsheet = $this->build($claim);

        $fileName = sprintf(
            'travel-expense-claim-%s-FY%d.xlsx',
            $claim->document_no ?: $claim->id,
            $claim->fiscal_year
        );

        // ไม่ใช้ tempnam()/sys_get_temp_dir() เพราะบางเครื่อง temp dir ของระบบ
        // ชี้ไปโฟลเดอร์ที่เขียนไม่ได้ (เช่น C:\Windows) แล้วทำให้ export ล้มทั้งคำขอ
        $tempDir = storage_path('app/tmp');
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0775, true);
        }

        $tempPath = $tempDir . DIRECTORY_SEPARATOR . 'tec_' . uniqid('', true) . '.xlsx';

        (new Xlsx($spreadsheet))->save($tempPath);
        $spreadsheet->disconnectWorksheets();

        return response()
            ->download($tempPath, $fileName, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])
            ->deleteFileAfterSend(true);
    }

    private function build(TravelExpenseClaim $claim): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('ใบเบิกค่าใช้จ่าย');

        foreach (self::WIDTHS as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }

        $headerRow = $this->writeHeading($sheet, $claim);
        $this->writeTableHeader($sheet, $headerRow);

        $row = $headerRow + 1;
        $firstDataRow = $row;

        foreach ($claim->items as $index => $item) {
            // ลำดับและจำนวนเงินเป็นตัวเลขที่ระบบคำนวณเอง — เขียนตรงได้
            $sheet->setCellValue("A{$row}", $index + 1);

            // ชื่อ-นามสกุลมาจากผู้ใช้ ต้องบังคับเป็น string เสมอ
            $this->setTextCell($sheet, "B{$row}", $item->first_name);
            $this->setTextCell($sheet, "C{$row}", $item->last_name);

            $sheet->setCellValue("D{$row}", (float) $item->allowance_amount);
            $sheet->setCellValue("E{$row}", (float) $item->accommodation_amount);
            $sheet->setCellValue("F{$row}", (float) $item->transportation_amount);
            $sheet->setCellValue("G{$row}", (float) $item->other_amount);
            $sheet->setCellValue("H{$row}", (float) $item->total_amount);

            $row++;
        }

        $lastDataRow = $row - 1;

        $this->writeTotalRow($sheet, $claim, $row);
        $this->applyTableStyle($sheet, $headerRow, $row, $firstDataRow, $lastDataRow);
        $this->writeSignature($sheet, $row + 3);

        return $spreadsheet;
    }

    /** หัวเรื่องด้านบน — merge A:H ทุกบรรทัด จัดกลาง คืนค่าแถวของหัวตาราง */
    private function writeHeading($sheet, TravelExpenseClaim $claim): int
    {
        $lines = [
            "(ภาคปีงบประมาณ {$claim->fiscal_year})",
            // บนแบบฟอร์มใช้ข้อความเต็มตามต้นฉบับ ไม่ใช่ชื่อย่อที่โชว์ในแอป
            // และใช้ข้อความเดียวกันทุกประเภท
            TravelExpenseClaim::EXCEL_CATEGORY_LABEL,
            $claim->organization_name ?: '',
            $claim->claim_period
                ? 'ประจำเดือน ' . ThaiFiscalYear::periodLabel($claim->claim_period)
                : '',
        ];

        if ($claim->isCancelled()) {
            $lines[] = '*** เอกสารนี้ถูกยกเลิก ***';
        }

        $row = 1;
        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }

            $this->setTextCell($sheet, "A{$row}", $line);
            $sheet->mergeCells("A{$row}:H{$row}");
            $sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize($row === 1 ? 14 : 12);
            $sheet->getStyle("A{$row}")->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $row++;
        }

        return $row + 1;
    }

    /**
     * เขียนค่าที่มาจากผู้ใช้เป็นข้อความธรรมดา
     *
     * setCellValue()/fromArray() จะตรวจสตริงที่ขึ้นต้นด้วย "=" แล้วสร้างเป็น "สูตร" Excel
     * ทำให้เกิด Excel Formula Injection (CWE-1236) — ค่าอย่าง "=cmd|' /C calc'!A0"
     * จะกลายเป็นคำสั่งที่ทำงานเมื่อผู้ใช้เปิดไฟล์ จึงต้องบังคับชนิดเป็น TYPE_STRING ทุกครั้ง
     */
    private function setTextCell($sheet, string $coordinate, ?string $value): void
    {
        $sheet->setCellValueExplicit($coordinate, (string) $value, DataType::TYPE_STRING);
    }

    private function writeTableHeader($sheet, int $row): void
    {
        // หัวตารางเป็นค่าคงที่ของระบบ ไม่ได้มาจากผู้ใช้ จึงเขียนผ่าน fromArray ได้
        $sheet->fromArray(self::HEADERS, null, "A{$row}");

        $style = $sheet->getStyle("A{$row}:H{$row}");
        $style->getFont()->setBold(true);
        $style->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setVertical(Alignment::VERTICAL_CENTER)
            ->setWrapText(true);
    }

    private function writeTotalRow($sheet, TravelExpenseClaim $claim, int $row): void
    {
        $items = $claim->items;

        $sheet->setCellValue("A{$row}", 'รวม');
        $sheet->mergeCells("A{$row}:C{$row}");
        $sheet->setCellValue("D{$row}", round((float) $items->sum('allowance_amount'), 2));
        $sheet->setCellValue("E{$row}", round((float) $items->sum('accommodation_amount'), 2));
        $sheet->setCellValue("F{$row}", round((float) $items->sum('transportation_amount'), 2));
        $sheet->setCellValue("G{$row}", round((float) $items->sum('other_amount'), 2));
        $sheet->setCellValue("H{$row}", round((float) $items->sum('total_amount'), 2));

        $sheet->getStyle("A{$row}:H{$row}")->getFont()->setBold(true);
        $sheet->getStyle("A{$row}")->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER);
    }

    /** border ทั้งตาราง + format จำนวนเงิน + จัดกลางคอลัมน์ลำดับ */
    private function applyTableStyle($sheet, int $headerRow, int $totalRow, int $firstDataRow, int $lastDataRow): void
    {
        $sheet->getStyle("A{$headerRow}:H{$totalRow}")
            ->getBorders()
            ->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN);

        $sheet->getStyle("D{$headerRow}:H{$totalRow}")
            ->getNumberFormat()
            ->setFormatCode(self::MONEY_FORMAT);

        if ($lastDataRow >= $firstDataRow) {
            $sheet->getStyle("A{$firstDataRow}:A{$lastDataRow}")
                ->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }
    }

    private function writeSignature($sheet, int $row): void
    {
        $sheet->setCellValue("E{$row}", '(ลงชื่อ)..................................................ผู้เบิก-จ่าย');
        $sheet->mergeCells("E{$row}:H{$row}");
        $sheet->getStyle("E{$row}")->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_LEFT);
    }
}
