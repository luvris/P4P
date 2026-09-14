<?php

namespace App\Services\Parsers;

use PhpOffice\PhpSpreadsheet\IOFactory;

class XlsxParser
{
    /**
     * Column mapping — ตรงกับ Excel จริง 100%
     */
    protected array $columnMap = [
        0  => null,                 // ปี
        1  => null,                 // เดือน
        2  => 'employee_type',      // ประเภท
        3  => 'citizen_id',         // บัตรประชาชน
        4  => 'first_name',         // ชื่อ (รวมคำนำหน้า)
        5  => 'last_name',          // นามสกุล
        6  => 'bank_account',       // เลขที่บัญชี
        7  => 'salary',             // เงินเดือน
        8  => null,                 // ตกเบิก (ข้าม)
        9  => 'living_allowance',   // ครองชีพ
        10 => null,                 // พตส (ข้าม)
        11 => null,                 // รักษา (ข้าม)
        12 => null,                 // เล่าเรียน (ข้าม)
        13 => null,                 // ล่วงเวลา (ข้าม)
        14 => null,                 // บ่ายดึก (ข้าม)
        15 => null,                 // อื่นๆ (ข้าม)
        16 => 'total_income',       // รวมรายรับ
        17 => 'social_security',    // ปกส
        18 => null,                 // หักวันลา (ข้าม)
        19 => 'electricity',        // ไฟ
        20 => 'water',              // น้ำ
        21 => 'health_insurance',   // สสจ
        22 => 'cooperative',        // ธ.สงเคราะห์
        23 => 'life_insurance',     // ฌกส
        24 => null,                 // ภาษี (ข้าม)
        25 => 'provident_fund',     // กองทุนสำรอง
        26 => 'student_loan',       // กยศ
        27 => null,                 // อื่นๆ (ข้าม)
        28 => 'total_deduction',    // รวมรายจ่าย
        29 => 'net_income',         // รับจริง
    ];

    public function supports(string $extension): bool
    {
        return in_array(strtolower($extension), ['xlsx', 'xls']);
    }

    public function parse(string $filePath): array
    {
        $spreadsheet = IOFactory::load($filePath);
        $worksheet = $spreadsheet->getActiveSheet();
        $rows = $worksheet->toArray(null, true, true, false);

        // ลบ Header row
        array_shift($rows);

        $data = [];

        foreach ($rows as $rowIndex => $row) {
            if ($this->isEmptyRow($row)) {
                continue;
            }

            $record = [];

            foreach ($this->columnMap as $index => $field) {
                if ($field === null) {
                    continue;
                }

                $value = $row[$index] ?? null;
                $record[$field] = $this->normalizeValue($value, $field);
            }

            $data[] = $record;
        }

        return $data;
    }

    protected function isEmptyRow(array $row): bool
    {
        foreach ($row as $cell) {
            if (!empty(trim((string) $cell))) {
                return false;
            }
        }
        return true;
    }

    protected function normalizeValue($value, string $field)
    {
        $stringFields = [
            'employee_type',
            'first_name',
            'last_name',
            'citizen_id',
            'bank_account',
        ];

        if ($value === null || $value === '') {
            return in_array($field, $stringFields) ? null : 0;
        }

        if (in_array($field, $stringFields)) {
            return trim((string) $value);
        }

        if (in_array($field, [
            'salary',
            'living_allowance',
            'total_income',
            'social_security',
            'electricity',
            'water',
            'health_insurance',
            'cooperative',
            'life_insurance',
            'provident_fund',
            'student_loan',
            'total_deduction',
            'net_income'
        ])) {
            $cleaned = str_replace([',', ' '], '', (string) $value);
            return is_numeric($cleaned) ? (float) $cleaned : 0;
        }

        return trim((string) $value);
    }
}
