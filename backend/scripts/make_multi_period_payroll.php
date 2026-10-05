<?php

/**
 * สร้างไฟล์ Excel ตัวอย่างรูปแบบใหม่ที่มี "หลายงวดในไฟล์เดียว"
 *
 * ใช้ทดสอบว่าอัปโหลดไฟล์ที่รวมหลายเดือน แล้วระบบเงินสำรอง
 * แยกเป็นรายงวดได้ถูกต้อง (แต่ละแถวมีคอลัมน์ ปี/เดือน ของตัวเอง)
 *
 * รัน: php make_multi_period_payroll.php [จำนวนคนต่องวด]
 */

require __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

$count = (int) ($argv[1] ?? 5);

// งวดที่ต้องการทดสอบ: ต.ค.–ธ.ค. 2568 (อยู่ในปีงบประมาณ 2569)
$periods = [
    ['ปี' => 2568, 'เดือน' => 'ตุลาคม'],
    ['ปี' => 2568, 'เดือน' => 'พฤศจิกายน'],
    ['ปี' => 2568, 'เดือน' => 'ธันวาคม'],
];

$header = [
    'ลำดับที่', 'ปี', 'เดือน', 'คำนำหน้า', 'ชื่อ', 'นามสกุล', 'ประเภท',
    'ตำแหน่ง', 'ตำแหน่งเลขที่', 'ID CARD', 'เลขที่บัญชี', 'เลขที่บัญชี.1',
    'เงินเดือน', 'ตกเบิก', 'ง.บ.ส.ก.', 'ปจต.', 'ค่าครองชีพ', 'ไม่ทำเวชฯ',
    'พตส.', 'ค่าOT', 'บ่าย-ดึก เงินงบประมาณ', 'บ่าย-ดึก เงินบำรุง',
    'P4P ประจำเดือน', 'ค่าตอบแทน ปฏิบัติงาน covid 19', 'P4P โครงการคุณภาพ',
    'รายได้อื่น', 'รวมรายรับทางตรง',
    'ค่ารักษา', 'ค่าเล่าเรียน', 'ค่าเบี้ยเลียง', 'ค่าเช่าที่พัก', 'ค่าพาหนะ',
    'ค่าใช้จ่ายอื่น ๆ', 'ต้นทุนจัดโครงการ',
    'ประกันสังคม นายจ้าง', 'กองทุนสำรอง เลี้ยงชีพ', 'รวมรายรับทางอ้อม',
    'ยอดรวมรายรับทั้งหมด รายบุคคล', 'หมายเหตุ',
];

$firstNames = ['xxxxx', 'yyyyy', 'zzzzz', 'aaaaa', 'bbbbb', 'ccccc'];
$lastNames  = ['xxxxxx', 'yyyyyy', 'zzzzzz', 'aaaaaa', 'bbbbbb', 'cccccc'];
$positions  = [
    'ผู้อำนวยการเฉพาะด้าน (แพทย์)สูง',
    'แพทย์ชั้นสูง',
    'รองผู้อำนวยการ',
    'พยาบาลชั้นสูง',
    'แพทย์',
    'นักวิชาการสาธารณสุข',
];

/** แสดงจำนวนแบบมี comma เหมือนไฟล์จริง */
$money = fn ($v) => $v === null ? ' - ' : number_format((float) $v, 2, '.', ',');

$rows   = [$header];
$seq    = 0;
$totals = array_fill(0, count($periods), 0);

foreach ($periods as $periodIndex => $period) {
    for ($i = 0; $i < $count; $i++) {
        $seq++;

        $salary       = 30000 + $i * 5000;
        $living       = 3000;
        $position     = 4000;
        $p4pMonthly   = 2500;
        $directTotal  = $salary + $living + $position + $p4pMonthly;
        $indirectTotal = 0;

        $totals[$periodIndex] += $directTotal;

        $rows[] = [
            $seq,
            $period['ปี'],
            $period['เดือน'],
            'นาย',
            $firstNames[$i % count($firstNames)],
            $lastNames[$i % count($lastNames)],
            'ข้าราชการ',
            $positions[$i % count($positions)],
            (string) (4415 + $i),
            '35299002735' . str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT),
            '536004153' . $i,
            '536004153' . $i,
            ' ' . $money($salary) . ' ',
            null, null,
            $money(0),
            $money($living),
            null,
            $money($position),
            $money(0),
            null, null,
            $money($p4pMonthly),
            null, null,
            $money(0),
            $money($directTotal),
            null, null, null, null, null, null, null,
            $money(0), $money(0), $money($indirectTotal),
            $money($directTotal + $indirectTotal),
            null,
        ];
    }

    // แถวรวมยอดท้ายแต่ละงวด — ต้องไม่ถูกนับเป็นบุคลากร
    $grand = $totals[$periodIndex];
    $totalRow = array_fill(0, 12, null);
    $totalRow[1]  = $period['ปี'];
    $totalRow[2]  = $period['เดือน'];
    $totalRow[12] = $money($grand);
    $totalRow[25] = $money($grand);
    $totalRow[36] = $money($grand);
    $rows[] = $totalRow;
}

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->fromArray($rows, null, 'A1');

$path = __DIR__ . '/../sample_payroll_multi_period.xlsx';
(new XlsxWriter($spreadsheet))->save($path);

echo "สร้างแล้ว: {$path}\n";
echo 'คอลัมน์: ' . count($header) . "\n";
echo 'งวด: ' . count($periods) . ' (' . implode(', ', array_column($periods, 'เดือน')) . ")\n";
echo 'แถวข้อมูล: ' . (count($periods) * $count) . ' คน-งวด + ' . count($periods) . " แถวรวมยอด\n";
