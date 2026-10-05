<?php

namespace App\Services\Parsers;

use PhpOffice\PhpSpreadsheet\IOFactory;

class XlsxParser
{
    /**
     * คอลัมน์รายรับที่ใช้เป็นฐานคำนวณเงินสำรอง + ชื่อที่ใช้แสดงให้ผู้ใช้อ่าน
     * ถ้าตัวใดตัวหนึ่งจับคู่คอลัมน์ไม่ได้ ค่านั้นจะกลายเป็น 0 เงียบ ๆ จึงต้องเตือน
     */
    protected const RESERVE_INCOME_LABELS = [
        'salary'             => 'เงินเดือน',
        'overtime'           => 'ล่วงเวลา (OT)',
        'position_allowance' => 'เงินประจำตำแหน่ง (พตส.)',
        'p4p_income'         => 'เงิน P4P',
    ];

    /**
     * field ผลรวมที่คำนวณจากคอลัมน์ย่อยแทนการจับจากหัวตารางโดยตรง
     * เช่น p4p_income = p4p_monthly + p4p_quality_project (ไฟล์รูปแบบใหม่)
     * ยังเก็บผลรวมไว้เพราะเป็นฐานคำนวณเงินสำรอง แต่คอลัมน์ย่อยถูกเก็บแยกต่างหาก
     */
    protected const DERIVED_SUM_FIELDS = [
        'p4p_income'      => ['p4p_monthly', 'p4p_quality_project'],
        'social_security' => ['social_security_employer', 'social_security_employee'],
    ];

    /**
     * รูปแบบ header แบบ merged ที่ครอบคอลัมน์ย่อย (ตรวจว่า normalized header
     * มีทั้งคำเหล่านี้ครบ) เช่น "ชื่อ - นามสกุล" ครอบ คำนำหน้า/ชื่อ/นามสกุล
     */
    protected const MERGED_HEADER_KEYWORD_SETS = [
        ['ชื่อ', 'นามสกุล'],
    ];

    /**
     * คอลัมน์ที่ resolve ได้จากไฟล์ล่าสุด (field => index หรือ [indexes] หรือ null)
     * เก็บไว้ให้ตรวจว่ามีคอลัมน์สำคัญหายไปหรือไม่
     */
    protected array $resolvedIndexes = [];

    /**
     * Fallback column mapping — ตรงกับ Excel รูปแบบเก่า (ใช้เฉพาะเมื่อ
     * จับชื่อ header จากไฟล์ไม่ได้แม้แต่คอลัมน์เดียว)
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
        10 => 'position_allowance', // พตส (เงินประจำตำแหน่ง)
        11 => null,                 // รักษา (ข้าม)
        12 => null,                 // เล่าเรียน (ข้าม)
        13 => 'overtime',           // ล่วงเวลา (OT)
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
     *
     * เรียงจากเฉพาะเจาะจง → กว้าง เพราะการเทียบแบบ "มีคำใน header" จะเดิน
     * ตามลำดับนี้ เช่น "รวมรายรับทางตรง" ต้องถูกจับเป็น total_income ก่อน
     * ที่ alias "รวมรายรับ" จะไปติดคอลัมน์ "ยอดรวมรายรับทั้งหมด รายบุคคล"
     */
    protected array $fieldAliases = [
        'employee_type'   => ['ประเภท', 'ประเภทบุคลากร', 'ประเภทพนักงาน', 'employee type', 'employee_type'],
        'citizen_id'      => ['เลขบัตรประชาชน', 'เลขที่บัตรประชาชน', 'บัตรประชาชน', 'เลขประจำตัวประชาชน', 'citizen id', 'citizen_id'],
        'first_name'      => ['ชื่อ', 'ชื่อ (รวมคำนำหน้า)', 'first name'],
        'last_name'       => ['นามสกุล', 'สกุล', 'last name'],
        'bank_account'    => ['เลขที่บัญชี', 'เลขบัญชี', 'บัญชี', 'bank account', 'bank_account'],
        'salary'          => ['เงินเดือน', 'salary'],
        'living_allowance'=> ['ค่าครองชีพ', 'ครองชีพ', 'living allowance'],
        'position_allowance' => ['พตส.', 'พตส', 'เงินประจำตำแหน่ง', 'ประจำตำแหน่ง', 'position allowance'],
        'overtime'        => ['ค่าot', 'ค่า ot', 'ล่วงเวลา', 'ค่าล่วงเวลา', 'โอที', 'ot', 'overtime'],
        'p4p_monthly'     => ['p4p ประจำเดือน'],
        'p4p_quality_project' => ['p4p โครงการคุณภาพ'],
        'p4p_income'      => ['p4p', 'เงิน p4p', 'p4p income'],
        'total_income'    => ['รวมรายรับทางตรง', 'รวมรายรับ', 'รวมรายได้', 'total income'],
        'social_security_employer' => ['ประกันสังคม นายจ้าง'],
        'social_security_employee' => ['ประกันสังคม ผู้ประกันตน'],
        'social_security' => ['ประกันสังคม', 'ปกส', 'social security'],
        'electricity'     => ['ค่าไฟฟ้า', 'ค่าไฟ', 'ไฟ', 'electricity'],
        'water'           => ['ค่าน้ำประปา', 'ค่าน้ำ', 'น้ำ', 'water'],
        'health_insurance'=> ['สสจ', 'health insurance'],
        'cooperative'     => ['ธ.สงเคราะห์', 'สงเคราะห์', 'cooperative'],
        'life_insurance'  => ['ฌกส', 'life insurance'],
        'provident_fund'  => ['กองทุนสำรองเลี้ยงชีพ', 'กองทุนสำรอง', 'provident fund'],
        'student_loan'    => ['กยศ', 'student loan'],
        'total_deduction' => ['รวมรายจ่ายทั้งหมด', 'รวมรายจ่าย', 'total deduction'],
        'net_income'      => ['ยอดรวมรายรับทั้งหมด รายบุคคล', 'ยอดรวมรายรับทั้งหมด', 'รับจริง', 'net income'],
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

        [$headerRowsConsumed, $header] = $this->extractHeader($rows);
        $dataRows = array_slice($rows, $headerRowsConsumed);

        // หา column index ของแต่ละ field (header-first, positional fallback เฉพาะจับ header ไม่ได้เลย)
        $resolved = $this->resolveFieldIndexes($header);
        $this->resolvedIndexes = $resolved;

        $data = [];

        foreach ($dataRows as $row) {
            if ($this->isEmptyRow($row)) {
                continue;
            }

            $record = [];

            foreach ($resolved as $field => $index) {
                $record[$field] = $this->extractValue($row, $index, $field);
            }

            // คอลัมน์ผลรวม (p4p_income / social_security) = ผลรวมของคอลัมน์ย่อย "ที่จับได้จากไฟล์"
            // ถ้าไม่มีคอลัมน์ย่อยเลย (ไฟล์เก่าจับหัว P4P ตรง ๆ) ใช้ค่าที่จับจากหัวผลรวมโดยตรง
            foreach (self::DERIVED_SUM_FIELDS as $sumField => $parts) {
                $sum = 0;
                $foundAny = false;

                foreach ($parts as $part) {
                    if (($this->resolvedIndexes[$part] ?? null) !== null) {
                        $sum += (float) ($record[$part] ?? 0);
                        $foundAny = true;
                    }
                }

                if ($foundAny) {
                    $record[$sumField] = $sum;
                }
            }

            $data[] = $record;
        }

        return $data;
    }

    /**
     * แยกส่วนหัวตารางออกจากแถวข้อมูล
     *
     * รองรับหัวตาราง 2 ชั้นแบบ merged เช่น "ชื่อ - นามสกุล" ครอบ
     * คำนำหน้า / ชื่อ / นามสกุล — แถวบนเป็นหัวกลุ่ม แถวล่างเป็นหัวย่อย
     * โดยใช้หัวย่อยเป็นหลัก แล้วเติมด้วยหัวกลุ่มในคอลัมน์ที่ไม่มีหัวย่อย
     *
     * @param  array<int, array<int, mixed>>  $rows
     * @return array{0: int, 1: array<int, string>}  [จำนวนแถวหัวตาราง, header ที่ใช้เทียบ]
     */
    protected function extractHeader(array $rows): array
    {
        // ไฟล์จริงมักมีแถวหัวเรื่อง/ทะเบียนคุมก่อนถึงหัวตารางจริง (อาจเป็นแถวที่ 1-5 หรือมากกว่า)
        // หาแถวหัวตารางจริง = แถวที่ชื่อตรงกับคอลัมน์ที่รู้จักหลายช่องที่สุด
        $headerStart = $this->findHeaderRow($rows);

        $rows = array_slice($rows, $headerStart);
        $first = $rows[0] ?? [];
        $second = $rows[1] ?? [];

        // ไม่ใช่หัว 2 ชั้น → ใช้แถวแรกเป็นหัวตารางเลย
        if (! $this->isTwoRowHeader($first, $second)) {
            return [$headerStart + 1, $this->expandMergedNameHeader($this->normalizeHeaderRow($first))];
        }

        // หัว 2 ชั้น: หัวย่อย (แถวล่าง) มีลำดับความสำคัญกว่า — คอลัมน์ที่ไม่มีหัวย่อย
        // ใช้หัวกลุ่มแทน และคอลัมน์ที่มีทั้งคู่ให้รวมคำ เช่น "P4P"/"ประจำเดือน" → "p4p ประจำเดือน"
        $header = [];

        foreach ($second as $index => $subName) {
            $group = $this->normalizeHeader($first[$index] ?? null);
            $sub = $this->normalizeHeader($subName);

            // หัวกลุ่มแบบ merged ครอบหลายคอลัมน์ (เซลล์ว่างในแถวบนไม่ได้แปลว่าไม่มีหัวกลุ่ม)
            if ($group === '') {
                $group = $this->lastGroup ?? '';
            } else {
                $this->lastGroup = $group;
            }

            if ($sub === '') {
                $header[$index] = $group;
            } elseif ($group !== '' && $group !== $sub && ! $this->isMergedNameHeader($group)) {
                $header[$index] = trim($group . ' ' . $sub);
            } else {
                $header[$index] = $sub;
            }
        }

        return [$headerStart + 2, $this->expandMergedNameHeader($header)];
    }

    /**
     * หาแถวที่เป็น "หัวตารางจริง" — แถวแรกที่ชื่อตรงกับคอลัมน์ที่รู้จักตั้งแต่ 2 ช่องขึ้นไป
     *
     * แถวหัวเรื่อง/ทะเบียนคุมด้านบนจะไม่ match alias คอลัมน์ (เช่น "ที่ - วาเล็กซ์",
     * "Ktb Online", "จังหวัดน่านรัฐ") จึงถูกข้ามไป
     */
    protected function findHeaderRow(array $rows): int
    {
        foreach ($rows as $i => $row) {
            if ($i > 20) {
                break; // กันสแกนทั้งไฟล์ หัวตารางต้องอยู่ไม่ไกลจากด้านบน
            }

            if ($this->countAliasMatches($row) >= 2) {
                return $i;
            }
        }

        return 0;
    }

    /**
     * ตรวจว่าเป็นหัวตาราง 2 ชั้นหรือไม่
     *
     * แถวบนต้องมีหัวกลุ่มแบบ merged และแถวล่างต้อง "เป็นหัวตาราง" ไม่ใช่แถวข้อมูล —
     * เช็คจากจำนวนช่องที่ตรงกับชื่อคอลัมน์ที่รู้จัก (แถวข้อมูลจะไม่ตรงกับ alias เกือบทั้งแถว)
     */
    protected function isTwoRowHeader(array $first, array $second): bool
    {
        if ($this->isEmptyRow($second) || ! $this->hasMergedGroupHeader($first)) {
            return false;
        }

        return $this->countAliasMatches($second) >= 1;
    }

    /**
     * นับจำนวนช่องในแถวที่ชื่อตรงกับ alias คอลัมน์ที่รู้จักพอดี
     */
    protected function countAliasMatches(array $row): int
    {
        static $allAliases = null;
        $allAliases ??= array_merge(...array_values($this->fieldAliases));

        $count = 0;

        foreach ($row as $cell) {
            if (in_array($this->normalizeHeader($cell), $allAliases, true)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * ขยายหัวแบบ merged "ชื่อ - นามสกุล" ที่ครอบหลายคอลัมน์ในแถวเดียว
     *
     * เช่น ["ชื่อ - นามสกุล", "", "", "ประเภท", ...] ครอบ คำนำหน้า/ชื่อ/นามสกุล
     * → ["", "ชื่อ", "นามสกุล", "ประเภท", ...] เพื่อให้จับคอลัมน์ชื่อ/นามสกุลแยกกันได้
     * (คอลัมน์คำนำหน้าปล่อยว่าง = ไม่ใช้)
     */
    protected function expandMergedNameHeader(array $header): array
    {
        foreach ($header as $index => $name) {
            if ($name === '' || ! $this->isMergedNameHeader($name)) {
                continue;
            }

            // ช่วงคอลัมน์ที่หัวนี้ครอบ = ช่องว่างต่อเนื่องกันจนถึงหัวถัดไป (ไม่เกิน 3)
            $span = 1;
            $next = $index + 1;

            while ($span < 3 && trim((string) ($header[$next] ?? '')) === '') {
                $span++;
                $next++;
            }

            if ($span < 2) {
                continue;
            }

            if ($span >= 3) {
                $header[$index] = '';              // คำนำหน้า — ไม่ใช้
                $header[$index + 1] = 'ชื่อ';
                $header[$index + 2] = 'นามสกุล';
            } else {
                $header[$index] = 'ชื่อ';           // ชื่อรวมคำนำหน้า
                $header[$index + 1] = 'นามสกุล';
            }
        }

        return $header;
    }

    /**
     * ตรวจว่าแถวนี้มีหัวกลุ่มแบบ merged ที่เรารู้จักหรือไม่
     * เช่น "ชื่อ - นามสกุล" (มีทั้งคำว่า ชื่อ และ นามสกุล ในช่องเดียว)
     */
    protected function hasMergedGroupHeader(array $row): bool
    {
        foreach ($row as $cell) {
            if ($this->isMergedNameHeader($this->normalizeHeader($cell))) {
                return true;
            }
        }

        return false;
    }

    /**
     * ชื่อ header นี้เป็นหัวกลุ่ม merged ที่เรารู้จักหรือไม่
     * เช่น "ชื่อ - นามสกุล" (มีทั้งคำว่า ชื่อ และ นามสกุล ในช่องเดียว)
     */
    protected function isMergedNameHeader(string $name): bool
    {
        if ($name === '') {
            return false;
        }

        foreach (self::MERGED_HEADER_KEYWORD_SETS as $keywords) {
            $allFound = true;

            foreach ($keywords as $keyword) {
                if (mb_strpos($name, $keyword) === false) {
                    $allFound = false;
                    break;
                }
            }

            if ($allFound) {
                return true;
            }
        }

        return false;
    }

    protected function normalizeHeaderRow(array $row): array
    {
        $normalized = [];

        foreach ($row as $index => $name) {
            $normalized[$index] = $this->normalizeHeader($name);
        }

        return $normalized;
    }

    /**
     * รวม field => column index(es) จากหัวตาราง
     *
     * @return array<string, int|array<int, int>|null>
     */
    protected function resolveFieldIndexes(array $header): array
    {
        $normalized = [];

        foreach ($header as $index => $name) {
            if ($name !== '') {
                $normalized[$index] = $name;
            }
        }

        // จับคู่จากชื่อ header — field ที่เป็น "ผลรวม" (p4p_income, social_security)
        // จับจากหัวตรง ๆ ได้เฉพาะเมื่อ "ไม่มี" คอลัมน์ย่อยในไฟล์ (เช่นไฟล์เก่าหัว "P4P" เดียว)
        // ถ้ามีคอลัมน์ย่อยจะถูกคำนวณจากคอลัมน์ย่อยแทน (แยกคอลัมน์เก็บ)
        $resolved = [];
        $matchedCount = 0;

        foreach ($this->fields() as $field) {
            $indexes = $this->matchColumns($field, $normalized);

            if (isset(self::DERIVED_SUM_FIELDS[$field]) && $indexes !== []) {
                foreach (self::DERIVED_SUM_FIELDS[$field] as $part) {
                    if (($resolved[$part] ?? null) !== null) {
                        $indexes = []; // มีคอลัมน์ย่อยแล้ว — ผลรวมคำนวณจากคอลัมน์ย่อย
                        break;
                    }
                }
            }

            if ($indexes !== []) {
                $matchedCount++;
                $resolved[$field] = $indexes[0];
            } else {
                $resolved[$field] = null;
            }
        }

        // จับ header จากชื่อไม่ได้เลย → ไฟล์รูปแบบเก่าที่ไม่มีหัวตาราง ใช้ตำแหน่งคอลัมน์เดิม
        if ($matchedCount === 0) {
            return $this->positionalFallback();
        }

        return $resolved;
    }

    /**
     * หาทุกคอลัมน์ที่ตรงกับ field หนึ่ง ๆ เรียงตามลำดับคอลัมน์ในไฟล์
     *
     * @param  array<int, string>  $normalized  normalized header คีย์ด้วย column index
     * @return array<int, int>
     */
    protected function matchColumns(string $field, array $normalized): array
    {
        $aliases = $this->fieldAliases[$field] ?? [];

        // 1) ชื่อตรงเป๊ะก่อน
        $exact = [];

        foreach ($normalized as $index => $name) {
            if (in_array($name, $aliases, true)) {
                $exact[] = $index;
            }
        }

        if ($exact !== []) {
            return $exact;
        }

        // 2) header ที่มีคำ alias อยู่ข้างใน (เช่น "กองทุนสำรอง เลี้ยงชีพ" มีคำ "กองทุนสำรอง")
        $partial = [];

        foreach ($aliases as $alias) {
            foreach ($normalized as $index => $name) {
                if (mb_strpos($name, $alias) !== false) {
                    $partial[$index] = true;
                }
            }

            if ($partial !== []) {
                break; // alias ที่เฉพาะเจาะจงกว่ามาก่อนชนะทั้งชุด
            }
        }

        return array_keys($partial);
    }

    /**
     * Fallback ตามตำแหน่งคอลัมน์เดิม (ไฟล์รูปแบบเก่าที่ไม่มีหัวตารางให้จับ)
     *
     * @return array<string, int|null>
     */
    protected function positionalFallback(): array
    {
        $resolved = [];

        foreach ($this->fields() as $field) {
            $resolved[$field] = null;
        }

        foreach ($this->columnMap as $index => $field) {
            if ($field !== null) {
                $resolved[$field] = $index;
            }
        }

        return $resolved;
    }

    /**
     * ชื่อคอลัมน์รายรับที่ "ไม่พบในไฟล์" — ค่าเหล่านั้นจะถูกบันทึกเป็น 0
     *
     * ใช้เตือนผู้ใช้หลังนำเข้า กันปัญหาคอลัมน์ P4P/OT หายไปเงียบ ๆ
     *
     * @return array<int, string> ชื่อที่ใช้แสดงผล
     */
    public function missingReserveIncomeFields(): array
    {
        $missing = [];

        foreach (self::RESERVE_INCOME_LABELS as $field => $label) {
            $resolved = $this->resolvedIndexes[$field] ?? null;

            // field ผลรวม (เช่น p4p_income) ถือว่า "มี" ถ้าคอลัมน์ย่อยอย่างน้อยหนึ่งตัวถูกจับได้
            foreach (self::DERIVED_SUM_FIELDS[$field] ?? [] as $part) {
                if (($this->resolvedIndexes[$part] ?? null) !== null) {
                    $resolved = true;
                    break;
                }
            }

            if ($resolved === null) {
                $missing[] = $label;
            }
        }

        return $missing;
    }

    /**
     * รายการ field (canonical keys) ที่จะส่งต่อให้ service
     *
     * รวม field ที่ไม่มีตำแหน่งคอลัมน์ fallback (เช่น p4p_income)
     * ซึ่งจับคู่ได้จากชื่อ header เท่านั้น
     */
    protected function fields(): array
    {
        $fromColumnMap = array_values(array_filter($this->columnMap, fn ($field) => $field !== null));
        $fromAliases = array_keys($this->fieldAliases);

        return array_values(array_unique([...$fromColumnMap, ...$fromAliases]));
    }

    /**
     * ดึงค่าของ field หนึ่ง ๆ จากแถวข้อมูล
     */
    protected function extractValue(array $row, int|null $index, string $field)
    {
        if ($index === null) {
            return $this->normalizeValue(null, $field);
        }

        return $this->normalizeValue($row[$index] ?? null, $field);
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

        if ($value === null || $value === '' || in_array(trim((string) $value), ['-', '–'], true)) {
            return in_array($field, $stringFields) ? null : 0;
        }

        if (in_array($field, $stringFields)) {
            $trimmed = trim((string) $value);

            // เลขที่บัญชีบางไฟล์มีช่องว่างคั่น (521 0 00000 3) — ตัดช่องว่างออกให้เทียบข้ามไฟล์ได้
            if ($field === 'bank_account') {
                $trimmed = str_replace(' ', '', $trimmed);
            }

            return $trimmed;
        }

        if (in_array($field, [
            'salary',
            'living_allowance',
            'overtime',
            'position_allowance',
            'p4p_income',
            'p4p_monthly',
            'p4p_quality_project',
            'total_income',
            'social_security',
            'social_security_employer',
            'social_security_employee',
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