<?php

namespace App\Services\Parsers;

use PhpOffice\PhpSpreadsheet\IOFactory;

class XlsxParser
{
    /**
     * Fallback column mapping — ตรงกับ Excel จริง (ใช้เมื่อจำชื่อ header ไม่ได้)
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

    /**
     * ชื่อ header (แบบ normalize แล้ว) → field
     * รองรับ header ที่สลับตำแหน่ง หรือเขียนไม่ตรงกัน
     */
    protected array $fieldAliases = [
        'employee_type'   => ['ประเภท', 'ประเภทบุคลากร', 'ประเภทพนักงาน', 'employee type', 'employee_type'],
        'citizen_id'      => ['บัตรประชาชน', 'เลขบัตรประชาชน', 'เลขที่บัตรประชาชน', 'citizen id', 'citizen_id'],
        'first_name'      => ['ชื่อ', 'ชื่อ (รวมคำนำหน้า)', 'first name'],
        'last_name'       => ['นามสกุล', 'สกุล', 'last name'],
        'bank_account'    => ['เลขที่บัญชี', 'เลขบัญชี', 'บัญชี', 'bank account', 'bank_account'],
        'salary'          => ['เงินเดือน', 'salary'],
        'living_allowance'=> ['ครองชีพ', 'ค่าครองชีพ', 'living allowance'],
        'total_income'    => ['รวมรายรับ', 'รวมรายได้', 'total income'],
        'social_security' => ['ปกส', 'ประกันสังคม', 'social security'],
        'electricity'     => ['ไฟ', 'ค่าไฟ', 'ค่าไฟฟ้า', 'electricity'],
        'water'           => ['น้ำ', 'ค่าน้ำ', 'ค่าน้ำประปา', 'water'],
        'health_insurance'=> ['สสจ', 'สสจ.', 'health insurance'],
        'cooperative'     => ['ธ.สงเคราะห์', 'สงเคราะห์', 'cooperative'],
        'life_insurance'  => ['ฌกส', 'ฌกส.', 'life insurance'],
        'provident_fund'  => ['กองทุนสำรอง', 'provident fund'],
        'student_loan'    => ['กยศ', 'กยศ.', 'student loan'],
        'total_deduction' => ['รวมรายจ่าย', 'รวมรายจ่ายทั้งหมด', 'total deduction'],
        'net_income'      => ['รับจริง', 'net income'],
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

        // อ่าน header row เพื่อจับคู่ชื่อคอลัมน์
        $header = array_shift($rows) ?? [];

        $headerIndexByName = [];
        foreach ($header as $index => $name) {
            $name = $this->normalizeHeader($name);
            if ($name === '') {
                continue;
            }

            $headerIndexByName[$name] ??= $index;
        }

        // หา column index ของแต่ละ field (header-first, positional fallback)
        $resolved = [];
        foreach ($this->fields() as $field) {
            $resolved[$field] = $this->resolveColumnIndex($field, $headerIndexByName);
        }

        $data = [];

        foreach ($rows as $row) {
            if ($this->isEmptyRow($row)) {
                continue;
            }

            $record = [];

            foreach ($resolved as $field => $index) {
                $value = $index === null ? null : ($row[$index] ?? null);
                $record[$field] = $this->normalizeValue($value, $field);
            }

            $data[] = $record;
        }

        return $data;
    }

    /**
     * รายการ field (canonical keys) ที่จะส่งต่อให้ service
     */
    protected function fields(): array
    {
        return array_values(array_filter($this->columnMap, fn ($field) => $field !== null));
    }

    /**
     * หา column index ของ field หนึ่ง ๆ
     */
    protected function resolveColumnIndex(string $field, array $headerIndexByName): ?int
    {
        // 1) จับคู่จากชื่อ header
        foreach ($this->fieldAliases[$field] ?? [] as $alias) {
            if (array_key_exists($alias, $headerIndexByName)) {
                return $headerIndexByName[$alias];
            }
        }

        // 2) fallback ตามตำแหน่งคอลัมน์เดิม
        foreach ($this->columnMap as $index => $mappedField) {
            if ($mappedField === $field) {
                return $index;
            }
        }

        return null;
    }

    /**
     * normalize ชื่อ header ให้เปรียบเทียบได้
     */
    protected function normalizeHeader($name): string
    {
        $name = mb_strtolower(trim((string) $name));
        $name = str_replace(['_', '-'], ' ', $name);
        $name = preg_replace('/\s+/', ' ', $name);

        return $name;
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