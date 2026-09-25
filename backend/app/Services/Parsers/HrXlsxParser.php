<?php

namespace App\Services\Parsers;

use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Parser สำหรับ HR Import ที่รองรับ 2 ชุดข้อมูล:
 * 1. ข้อมูลส่วนตัวพนักงาน (employees)
 * 2. ข้อมูลการจ้างงาน (employment_histories)
 */
class HrXlsxParser
{
    /**
     * Column mapping สำหรับข้อมูลส่วนตัวพนักงาน
     */
    protected array $employeeColumnMap = [
        'PID'        => 'employee_id',      // เลขไอดีภายใน (ไม่ใช่บัตรประชาชน)
        'HID'        => 'citizen_id',       // เลขบัตรประชาชนจริง
        'TTL'        => 'prefix',
        'NAME'       => 'first_name',
        'LNAME'      => 'last_name',
        'SEX'        => 'sex',
        'BLOOD'      => 'blood_type',
        'BRON'       => 'birth_date',
        'TEL'        => 'tel',
        'MOBILE'     => 'mobile',
        'ADDRESS1'   => 'address1',
        'ADDRESS2'   => 'address2',
        'ETTL'       => 'english_prefix',           // คำนำหน้าภาษาอังกฤษ
        'ENAME'      => 'english_first_name',       // ชื่อภาษาอังกฤษ
        'ELNAME'     => 'english_last_name',        // นามสกุลภาษาอังกฤษ
        'STATUS'     => 'status',
        'LEAVES_BY'  => 'leaves_by',
        'FINGER'     => 'finger',
        'EMAIL'      => 'email',
        'TOKENLINE'  => 'line_token',
    ];

    /**
     * Column mapping สำหรับข้อมูลการจ้างงาน
     */
    protected array $employmentColumnMap = [
        'SER'       => 'serial_number',
        'PID'       => 'employee_id',        // เลขไอดีภายใน (ใช้เชื่อมกับ person.xlsx)
        'WID'       => 'wid',
        'EMPLOYEE'  => 'employee_type',      // ประเภทพนักงาน
        'WORKID'    => 'work_id',
        'MANAGE'    => 'manager',
        'POSITION'  => 'position',
        'CLASS'     => 'class',
        'CONDITIO'  => 'condition',
        'DATES'     => 'start_date',
        'DATEE'     => 'end_date',
        'EXP'       => 'experience',
        'MARK'      => 'mark',
        'STATUS'    => 'status',              // สถานะของแต่ละประวัติ
        'PAYROLL'   => 'payroll',
        'DATEDIRE'  => 'appointment_date',
        'CODEDIRE'  => 'appointment_code',
    ];

    /**
     * Field aliases สำหรับจับคู่ header
     */
    protected array $fieldAliases = [
        'employee_id'                => ['pid', 'เลขไอดี'],
        'citizen_id'                 => ['hid', 'เลขบัตรประชาชน', 'บัตรประชาชน'],
        'prefix'                     => ['ttl', 'คำนำหน้า'],
        'first_name'                 => ['name', 'ชื่อ'],
        'last_name'                  => ['lname', 'นามสกุล'],
        'sex'                        => ['sex', 'เพศ'],
        'blood_type'                 => ['blood', 'กรุ๊ปเลือด'],
        'birth_date'                 => ['bron', 'วันเกิด'],
        'tel'                        => ['tel', 'โทรศัพท์'],
        'mobile'                     => ['mobile', 'มือถือ'],
        'address1'                   => ['address1', 'ที่อยู่ 1'],
        'address2'                   => ['address2', 'ที่อยู่ 2'],
        'emergency_contact_prefix'   => ['ettl'],
        'emergency_contact_name'     => ['ename'],
        'emergency_contact_lname'    => ['elname'],
        'status'                     => ['status', 'สถานะ'],
        'leaves_by'                  => ['leaves_by'],
        'finger'                     => ['finger', 'ลายนิ้วมือ'],
        'email'                      => ['email', 'อีเมล'],
        'line_token'                 => ['tokenline'],
        'serial_number'              => ['ser', 'เลขที่'],
        'wid'                        => ['wid'],
        'employee_type'              => ['employee', 'ประเภท'],
        'work_id'                    => ['workid'],
        'manager'                    => ['manage', 'ผ้จัดการ'],
        'position'                   => ['position', 'ตำแหน่ง'],
        'class'                      => ['class', 'ระดับ'],
        'condition'                  => ['conditio', 'เงื่อนไข'],
        'start_date'                 => ['dates', 'วันที่เริ่มงาน'],
        'end_date'                   => ['datee', 'วันที่สิ้นสุด'],
        'experience'                 => ['exp', 'ประสบการณ์'],
        'mark'                       => ['mark', 'หมายเหตุ'],
        'status'                     => ['status', 'สถานะ'],  // เพิ่ม status ใน employment_histories
        'payroll'                    => ['payroll', 'เงินเดือน'],
        'appointment_date'           => ['datedire'],
        'appointment_code'           => ['codedire'],
    ];

    public function supports(string $extension): bool
    {
        return in_array(strtolower($extension), ['xlsx', 'xls']);
    }

    /**
     * Parse ไฟล์ Excel และแยกข้อมูลออกเป็น 2 ชุด
     */
    public function parse(string $filePath): array
    {
        $spreadsheet = IOFactory::load($filePath);
        $sheetCount = $spreadsheet->getSheetCount();
        
        if ($sheetCount >= 2) {
            return [
                'employees'   => $this->parseSheet($spreadsheet->getSheet(0), $this->employeeColumnMap),
                'employments' => $this->parseSheet($spreadsheet->getSheet(1), $this->employmentColumnMap),
            ];
        } else {
            $worksheet = $spreadsheet->getActiveSheet();
            $rows = $worksheet->toArray(null, true, true, false);
            $header = array_shift($rows) ?? [];
            
            $type = $this->detectDataType($header);
            
            if ($type === 'combined') {
                // ไฟล์รวม - แยกข้อมูล employee และ employment
                return $this->parseCombinedFile($rows, $header);
            } elseif ($type === 'employee') {
                return [
                    'employees'   => $this->parseRows($rows, $header, $this->employeeColumnMap),
                    'employments' => [],
                ];
            } else {
                return [
                    'employees'   => [],
                    'employments' => $this->parseRows($rows, $header, $this->employmentColumnMap),
                ];
            }
        }
    }
    
    /**
     * ตรวจจับว่าเป็นไฟล์แบบไหน
     */
    protected function detectDataType(array $header): string
    {
        $normalizedHeader = array_map(fn($h) => $this->normalizeHeader($h), $header);
        
        // เช็คว่ามี column ทั้ง employee และ employment หรือไม่
        $hasEmployeeColumns = in_array('pid', $normalizedHeader) && 
                             in_array('name', $normalizedHeader);
        
        $hasEmploymentColumns = in_array('ser', $normalizedHeader) && 
                               in_array('position', $normalizedHeader);
        
        if ($hasEmployeeColumns && $hasEmploymentColumns) {
            return 'combined'; // ไฟล์รวม
        } elseif ($hasEmployeeColumns) {
            return 'employee';
        } else {
            return 'employment';
        }
    }
    
    /**
     * Parse ไฟล์ที่มีข้อมูลรวมกัน
     */
    protected function parseCombinedFile(array $rows, array $header): array
    {
        // Merge column maps
        $allColumnMap = array_merge($this->employeeColumnMap, $this->employmentColumnMap);
        
        // Parse ทุกแถว
        $allData = $this->parseRows($rows, $header, $allColumnMap);
        
        // แยกข้อมูล
        $employees = [];
        $employments = [];
        $seenPids = [];
        
        foreach ($allData as $row) {
            $pid = $row['employee_id'] ?? null;
            if (!$pid) continue;
            
            // เก็บข้อมูล employee (ครั้งแรกที่เจอ PID)
            if (!isset($seenPids[$pid])) {
                $employeeData = [];
                foreach ($this->employeeColumnMap as $excelCol => $dbField) {
                    if (isset($row[$dbField])) {
                        $employeeData[$dbField] = $row[$dbField];
                    }
                }
                if (!empty($employeeData)) {
                    $employees[] = $employeeData;
                    $seenPids[$pid] = true;
                }
            }
            
            // เก็บประวัติ (ทุกแถว) - ต้องมี serial_number
            if (!empty($row['serial_number'])) {
                $employmentData = [];
                foreach ($this->employmentColumnMap as $excelCol => $dbField) {
                    if (isset($row[$dbField])) {
                        $employmentData[$dbField] = $row[$dbField];
                    }
                }
                if (!empty($employmentData)) {
                    $employments[] = $employmentData;
                }
            }
        }
        
        return [
            'employees'   => $employees,
            'employments' => $employments,
        ];
    }

    protected function parseSheet($worksheet, array $columnMap): array
    {
        $rows = $worksheet->toArray(null, true, true, false);
        $header = array_shift($rows) ?? [];
        return $this->parseRows($rows, $header, $columnMap);
    }

    protected function parseRows(array $rows, array $header, array $columnMap): array
    {
        $headerIndexByName = [];
        foreach ($header as $index => $name) {
            $name = $this->normalizeHeader($name);
            if ($name === '') continue;
            $headerIndexByName[$name] ??= $index;
        }

        $resolved = [];
        foreach (array_values($columnMap) as $field) {
            $resolved[$field] = $this->resolveColumnIndex($field, $headerIndexByName);
        }

        $data = [];
        foreach ($rows as $row) {
            if ($this->isEmptyRow($row)) continue;

            $record = [];
            foreach ($resolved as $field => $index) {
                $value = $index === null ? null : ($row[$index] ?? null);
                $record[$field] = $this->normalizeValue($value, $field);
            }
            $data[] = $record;
        }

        return $data;
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
            if (!empty(trim((string) $cell))) return false;
        }
        return true;
    }

    protected function normalizeValue($value, string $field)
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        if ($field === 'citizen_id') {
            return preg_replace('/\D+/', '', (string) $value) ?: null;
        }

        if (in_array($field, ['birth_date', 'start_date', 'end_date', 'appointment_date'])) {
            return $this->normalizeDate($value);
        }

        if ($field === 'payroll') {
            $cleaned = str_replace([',', ' '], '', (string) $value);
            return is_numeric($cleaned) ? (float) $cleaned : null;
        }

        return trim((string) $value) ?: null;
    }

    protected function normalizeDate($value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        if (is_numeric($value)) {
            try {
                $date = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($value);
                return $date->format('Y-m-d');
            } catch (\Exception $e) {
                return null;
            }
        }

        try {
            $date = new \DateTime((string) $value);
            return $date->format('Y-m-d');
        } catch (\Exception $e) {
            return null;
        }
    }
}

