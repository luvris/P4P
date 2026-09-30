<?php

namespace App\Services\Parsers;

use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Parser สำหรับ "นำเข้าข้อมูลการอยู่ภารกิจของบุคลากร"
 *
 * แยกจาก HrXlsxParser โดยสิ้นเชิง — ไม่แชร์ column map / logic กับ import บุคลากรเดิม
 *
 * รูปแบบไฟล์ (1 sheet, แถวแรกเป็น header):
 *   PID | ภารกิจ (DUTY) | PARTY (กลุ่มงาน) | AGENCIES (งาน)
 *
 * ค่าในไฟล์อาจมีรหัสนำหน้า เช่น "59_กลุ่มงานการพยาบาลผู้ป่วยนอก"
 * และคำนำหน้า เช่น "ภารกิจด้านการพยาบาล" — การตัดคำเหล่านี้ทำที่ service ตอนจับคู่
 */
class DutyAssignmentXlsxParser
{
    /**
     * field => รายการ header ที่ยอมรับ (normalize แล้ว)
     */
    protected array $fieldAliases = [
        'pid'   => ['pid', 'รหัสบุคลากร', 'รหัสพนักงาน', 'เลขไอดี', 'employee id'],
        'duty'  => ['duty', 'ภารกิจ', 'duty name'],
        'group' => ['party', 'group', 'กลุ่มงาน', 'group name'],
        'work'  => ['agencies', 'work', 'งาน', 'work name'],
    ];

    public function supports(string $extension): bool
    {
        return in_array(strtolower($extension), ['xlsx', 'xls'], true);
    }

    /**
     * @return array<int, array{pid: ?string, duty: ?string, group: ?string, work: ?string, row: int}>
     */
    public function parse(string $filePath): array
    {
        $spreadsheet = IOFactory::load($filePath);
        $worksheet = $spreadsheet->getActiveSheet();

        $rows = $worksheet->toArray(null, true, true, false);
        $header = array_shift($rows) ?? [];

        $headerIndexByName = [];
        foreach ($header as $index => $name) {
            $normalized = $this->normalizeHeader($name);
            if ($normalized === '') {
                continue;
            }
            $headerIndexByName[$normalized] ??= $index;
        }

        $resolved = [];
        foreach (array_keys($this->fieldAliases) as $field) {
            $resolved[$field] = $this->resolveColumnIndex($field, $headerIndexByName);
        }

        $data = [];
        foreach ($rows as $offset => $row) {
            if ($this->isEmptyRow($row)) {
                continue;
            }

            $record = ['row' => $offset + 2]; // +2 = ข้าม header และนับเริ่มที่ 1
            foreach ($resolved as $field => $index) {
                $value = $index === null ? null : ($row[$index] ?? null);
                $record[$field] = $this->normalizeValue($value);
            }

            $data[] = $record;
        }

        return $data;
    }

    /**
     * header ที่ระบบต้องการแต่ไม่พบในไฟล์ (ใช้เตือนผู้ใช้)
     */
    public function missingColumns(string $filePath): array
    {
        $spreadsheet = IOFactory::load($filePath);
        $rows = $spreadsheet->getActiveSheet()->toArray(null, true, true, false);
        $header = $rows[0] ?? [];

        $headerIndexByName = [];
        foreach ($header as $index => $name) {
            $normalized = $this->normalizeHeader($name);
            if ($normalized !== '') {
                $headerIndexByName[$normalized] ??= $index;
            }
        }

        $missing = [];
        foreach (array_keys($this->fieldAliases) as $field) {
            if ($this->resolveColumnIndex($field, $headerIndexByName) === null) {
                $missing[] = strtoupper($field);
            }
        }

        return $missing;
    }

    protected function resolveColumnIndex(string $field, array $headerIndexByName): ?int
    {
        foreach ($this->fieldAliases[$field] ?? [] as $alias) {
            $normalized = $this->normalizeHeader($alias);
            if (array_key_exists($normalized, $headerIndexByName)) {
                return $headerIndexByName[$normalized];
            }
        }

        return null;
    }

    protected function normalizeHeader($name): string
    {
        $name = mb_strtolower(trim((string) $name));
        $name = str_replace(['_', '-'], ' ', $name);

        return preg_replace('/\s+/', ' ', trim($name));
    }

    protected function isEmptyRow(array $row): bool
    {
        foreach ($row as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }

    protected function normalizeValue($value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);
        $value = preg_replace('/\s+/u', ' ', $value);

        return $value === '' ? null : $value;
    }
}
