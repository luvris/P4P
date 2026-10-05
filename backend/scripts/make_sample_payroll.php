<?php

/**
 * สร้างไฟล์ Excel ตัวอย่างตามรูปแบบใหม่ 39 คอลัมน์ สำหรับทดสอบ import จริง
 *
 * รัน: php make_sample_payroll.php [พ.ศ.] [เดือนไทย] [จำนวนแถว]
 */

require __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

$year  = $argv[1] ?? '2568';
$month = $argv[2] ?? 'ตุลาคม';
$count = (int) ($argv[3] ?? 5);

$header = [
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

$firstNames  = ['xxxxx', 'yyyyy', 'zzzzz', 'aaaaa', 'bbbbb', 'ccccc'];
$lastNames   = ['xxxxxx', 'yyyyyy', 'zzzzzz', 'aaaaaa', 'bbbbbb', 'cccccc'];
$positions   = [
    'ผู้อำนวยการเฉพาะด้าน (แพทย์)สูง',
    'แพทย์ชั้นสูง',
    'รองผู้อำนวยการ',
    'พยาบาลชั้นสูง',
    'แพทย์',
    'นักวิชาการสาธารณสุข',
];

/** แสดงจำนวนแบบมี comma เหมือนไฟล์จริง */
$money = fn ($v) => $v === null ? ' - ' : number_format((float) $v, 2, '.', ',');

$rows = [$header];

for ($i = 0; $i < $count; $i++) {
    $salary       = 30000 + $i * 5000;
    $living       = 3000;
    $position     = 4000;
    $p4pMonthly   = 2500;
    $otherIncome  = 0;
    $directTotal  = $salary + $living + $position + $p4pMonthly + $otherIncome;
    $indirectTotal = 0;

    $row = [
        $i + 1,
        $year,
        $month,
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
        null,                    // ตกเบิก
        null,                    // ง.บ.ส.ก.
        $money(0),               // ปจต.
        $money($living),
        null,                    // ไม่ทำเวชฯ
        $money($position),
        $money(0),               // ค่าOT
        null,                    // บ่าย-ดึก งบประมาณ
        null,                    // บ่าย-ดึก บำรุง
        $money($p4pMonthly),
        null,                    // covid
        null,                    // P4P โครงการคุณภาพ
        $money($otherIncome),
        $money($directTotal),
        null,                    // ค่ารักษา
        null,                    // ค่าเล่าเรียน
        null,                    // ค่าเบี้ยเลี้ยง
        null,                    // ค่าเช่าที่พัก
        null,                    // ค่าพาหนะ
        null,                    // ค่าใช้จ่ายอื่น ๆ
        null,                    // ต้นทุนจัดโครงการ
        $money(0),               // ประกันสังคม นายจ้าง
        $money(0),               // กองทุนสำรอง
        $money($indirectTotal),
        $money($directTotal + $indirectTotal),
        null,
    ];

    $rows[] = $row;
}

// แถวรวมยอดท้ายไฟล์ — ต้องไม่ถูกนับเป็นบุคลากร
$grandTotal = $count * 40000;
$rows[] = array_fill(0, 12, null);
$rows[count($rows) - 1][12] = $money($grandTotal);
$rows[count($rows) - 1][25] = $money($grandTotal);
$rows[count($rows) - 1][36] = $money($grandTotal);

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->fromArray($rows, null, 'A1');

$path = __DIR__ . '/../sample_payroll_new_format.xlsx';
(new XlsxWriter($spreadsheet))->save($path);

echo "สร้างแล้ว: {$path}\n";
echo 'คอลัมน์: ' . count($header) . ', แถวข้อมูล: ' . $count . " คน + 1 แถวรวมยอด\n";