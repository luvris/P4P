<?php

namespace App\Services\Parsers;

use App\Models\ReserveFundCalculation;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Parser สำหรับไฟล์ payroll รูปแบบใหม่
 *
 * โครงสร้างไฟล์: หัวตาราง 1 แถว แล้วข้อมูลต่อจากนั้น
 * แต่ละแถวมี ปี (พ.ศ.) และ เดือน (ชื่อไทย) ของตัวเอง จึงเก็บงวดเป็นรายแถว
 * ตัวเลขมาจากการคูณล้าง Excel หรือพิมพ์เอง จึงมีได้ทั้งจำนวนเต็ม ไม่มี comma
 * และค่าว่างแทนตัวเลข (เช่น " - ") ซึ่งถือเป็น 0
 *
 * ฐานคำนวณเงินสำรองคือคอลัมน์ "ยอดรวมรายรับทั้งหมด รายบุคคล" ตามที่ฝ่ายการเงินกำหนด
 */
class NewFormatPayrollParser
{
    /**
     * ชื่อ header ในไฟล์ → field ของ payrolls
     *
     * เรียงตามคอลัมน์จริงในไฟล์ และใช้ชื่อแบบเต็มเพื่อไม่ให้ไปจับกับคอลัมน์อื่น
     */
    protected const COLUMN_MAP = [
        'ลำดับที่'                 => 'seq_number',
        'คำนำหน้า'                 => 'prefix',
        'ชื่อ'                     => 'first_name',
        'นามสกุล'                  => 'last_name',
        'ประเภท'                   => 'employee_type',
        'ตำแหน่ง'                   => 'position_name',
        'ตำแหน่งเลขที่'              => 'position_number',
        'id card'                  => 'citizen_id',
        'เลขที่บัญชี'                => 'bank_account',
        'เงินเดือน'                 => 'salary',
        'ตกเบิก'                    => 'salary_deduction',
        'ง.บ.ส.ก.'                 => 'project_budget',
        'ปจต.'                     => 'regular_allowance',
        'ค่าครองชีพ'                => 'living_allowance',
        'ไม่ทำเวชฯ'                => 'no_medical_service',
        'พตส.'                     => 'position_allowance',
        'ค่าot'                     => 'overtime',
        'บ่าย-ดึก เงินงบประมาณ'     => 'night_shift_budget',
        'บ่าย-ดึก เงินบำรุง'        => 'night_shift_maintenance',
        'p4p ประจำเดือน'             => 'p4p_monthly',
        'ค่าตอบแทน ปฏิบัติงาน covid 19' => 'covid_allowance',
        'p4p โครงการคุณภาพ'        => 'p4p_quality_project',
        'รายได้อื่น'                => 'other_income',
        'รวมรายรับทางตรง'           => 'total_direct_income',
        'ค่ารักษา'                  => 'medical_treatment',
        'ค่าเล่าเรียน'               => 'education_allowance',
        'ค่าเบี้ยเลี้ยง'            => 'meal_allowance',
        'ค่าเช่าที่พัก'              => 'housing_allowance',
        'ค่าพาหนะ'                  => 'transport_allowance',
        'ค่าใช้จ่ายอื่น ๆ'           => 'other_expenses',
        'ต้นทุนจัดโครงการ'           => 'project_cost',
        'ประกันสังคม นายจ้าง'        => 'social_security_employer',
        'กองทุนสำรอง เลี้ยงชีพ'      => 'provident_fund',
        'รวมรายรับทางอ้อม'           => 'total_indirect_income',
        'ยอดรวมรายรับทั้งหมด รายบุคคล' => 'total_income',
        'หมายเหตุ'                  => 'note',
    ];

    /** คอลัมน์ ปี / เดือน จัดการแยก เพราะต้องแปลงค่าก่อน */
    protected const YEAR_COLUMN  = 'ปี';
    protected const MONTH_COLUMN = 'เดือน';

    /**
     * คอลัมน์ที่เป็นข้อความ (เก็บเป็น string ไม่ต้องแปลงเป็นตัวเลข)
     *
     * @var array<int, string>
     */
    protected const TEXT_FIELDS = [
        'prefix', 'first_name', 'last_name', 'employee_type',
        'position_name', 'position_number', 'citizen_id', 'bank_account', 'note',
    ];

    /** คอลัมน์ตัวเลขที่ต้องบันทึกเป็นจำนวนเต็ม */
    protected const INTEGER_FIELDS = ['seq_number'];

    /** เดือนภาษาไทย → เลขเดือน */
    protected const THAI_MONTHS = [
        'มกราคม' => 1, 'ม.ค.' => 1,
        'กุมภาพันธ์' => 2, 'ก.พ.' => 2,
        'มีนาคม' => 3, 'มี.ค.' => 3,
        'เมษายน' => 4, 'เม.ย.' => 4,
        'พฤษภาคม' => 5, 'พ.ค.' => 5,
        'มิถุนายน' => 6, 'มิ.ย.' => 6,
        'กรกฎาคม' => 7, 'ก.ค.' => 7,
        'สิงหาคม' => 8, 'ส.ค.' => 8,
        'กันยายน' => 9, 'ก.ย.' => 9,
        'ตุลาคม' => 10, 'ต.ค.' => 10,
        'พฤศจิกายน' => 11, 'พ.ย.' => 11,
        'ธันวาคม' => 12, 'ธ.ค.' => 12,
    ];

    /**
     * field ที่เป็นจำนวนเงิน (ทั้งหมดที่ไม่ใช่ข้อความและไม่ใช่เลขลำดับ/งวด)
     *
     * @var array<int, string>
     */
    protected const MONEY_FIELDS = [
        'salary', 'salary_deduction', 'project_budget', 'regular_allowance',
        'living_allowance', 'no_medical_service', 'position_allowance', 'overtime',
        'night_shift_budget', 'night_shift_maintenance',
        'p4p_monthly', 'covid_allowance', 'p4p_quality_project', 'other_income',
        'total_direct_income',
        'medical_treatment', 'education_allowance', 'meal_allowance',
        'housing_allowance', 'transport_allowance', 'other_expenses', 'project_cost',
        'social_security_employer', 'provident_fund', 'total_indirect_income',
        'total_income',
    ];

    /**
     * ลำดับคอลัมน์หัวตารางตามไฟล์จริง (39 คอลัมน์)
     *
     * ใช้เป็นแบบฟอร์มกรอกข้อมูล — จัดไว้ที่ parser เพื่อให้ไฟล์ต้นแบบ
     * ตรงกับสิ่งที่ parser อ่านเสมอ (ถ้าย้ายไปไว้ที่อื่นจะหลุดได้)
     *
     * @var array<int, string>
     */
    public const TEMPLATE_COLUMNS = [
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

    /** ชื่อเดือนไทยเต็มที่ใช้เลือกในไฟล์ต้นแบบ (ใส่ dropdown ให้เลือกได้) */
    public const TEMPLATE_MONTHS = [
        'มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน',
        'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม',
    ];

    /**
     * หัวตารางที่ parser บังคับต้องเจอ ถ้าไม่มีจะไม่ผ่านการอัปโหลด
     *
     * @var array<int, string>
     */
    public const CRITICAL_COLUMNS = ['ลำดับที่', 'ปี', 'เดือน', 'ชื่อ', 'นามสกุล'];

    /**
     * คอลัมน์ฐานเงินสำรอง — ขาดแล้วยอดเงินสำรองจะผิด
     *
     * @var array<int, string>
     */
    public const RESERVE_BASE_COLUMNS = ['ยอดรวมรายรับทั้งหมด รายบุคคล', 'เลขที่บัญชี'];

    /** field ที่ parser นี้จะเขียนลง payrolls (ทุก field จากไฟล์ + งวด) */
    protected const OUTPUT_FIELDS = [
        'seq_number', 'fiscal_year', 'period_month', 'period_year',
        'prefix', 'first_name', 'last_name', 'employee_type',
        'position_name', 'position_number', 'citizen_id', 'bank_account',
        'salary', 'salary_deduction', 'project_budget', 'regular_allowance',
        'living_allowance', 'no_medical_service', 'position_allowance', 'overtime',
        'night_shift_budget', 'night_shift_maintenance',
        'p4p_monthly', 'covid_allowance', 'p4p_quality_project', 'other_income',
        'total_direct_income',
        'medical_treatment', 'education_allowance', 'meal_allowance',
        'housing_allowance', 'transport_allowance', 'other_expenses', 'project_cost',
        'social_security_employer', 'provident_fund', 'total_indirect_income',
        'total_income', 'note',
    ];

    /** คอลัมน์ที่ขาดแล้วฐานเงินสำรองจะผิด → ใช้เตือนผู้ใช้ */
    protected const REQUIRED_COLUMNS = [
        'ยอดรวมรายรับทั้งหมด รายบุคคล' => 'ยอดรวมรายรับทั้งหมด รายบุคคล',
        'เลขที่บัญชี'                  => 'เลขที่บัญชี',
    ];

    /**
     * ชื่อเดือนที่ parser รับได้ทั้งหมด (ชื่อเต็มและตัวย่อ)
     *
     * @return array<int, string>
     */
    public static function acceptedMonthLabels(): array
    {
        return array_keys(self::THAI_MONTHS);
    }

    /**
     * ชื่อหัวตารางทั้งหมดที่ parser อ่านได้ (ใช้เทียบกับไฟล์ต้นแบบ)
     *
     * คืนค่าเป็น "ชื่อคอลัมน์ในไฟล์" ไม่ใช่ชื่อ field ในฐานข้อมูล
     *
     * @return array<int, string>
     */
    public static function readableColumns(): array
    {
        return array_merge(
            ['ลำดับที่', 'ปี', 'เดือน'],
            array_keys(self::COLUMN_MAP)
        );
    }

    /** field ที่จับคอลัมน์ในไฟล์ล่าสุดได้ → ชื่อคอลัมน์ที่ใช้ */
    protected array $resolvedColumns = [];

    public function supports(string $extension): bool
    {
        return in_array(strtolower($extension), ['xlsx', 'xls']);
    }

    /**
     * ไฟล์นี้เป็นรูปแบบใหม่หรือไม่
     *
     * รูปแบบใหม่มีคอลัมน์ ลำดับที่ / ปี / เดือน อยู่ในทุกแถว ซึ่งเป็นสิ่งที่ไฟล์รูปแบบเดิมไม่มี
     * จึงใช้สามคอลัมน์นี้เป็นตัวตัดสิน ส่วนคอลัมน์รายรับใช้ยืนยันว่าเป็นไฟล์ payroll
     */
    public static function looksLikeNewFormat(string $filePath): bool
    {
        try {
            $sheet = IOFactory::load($filePath)->getActiveSheet();
        } catch (\Throwable) {
            return false;
        }

        $rows = $sheet->toArray(null, true, true, false);

        foreach (array_slice($rows, 0, 20) as $row) {
            $headers = array_map(
                fn ($cell) => self::normalizeHeader($cell),
                array_values(array_filter($row, fn ($v) => trim((string) $v) !== ''))
            );

            $hasPeriodColumns = in_array('ลำดับที่', $headers, true)
                && in_array('ปี', $headers, true)
                && in_array('เดือน', $headers, true);

            if (! $hasPeriodColumns) {
                continue;
            }

            // ต้องมีคอลัมน์รายรับของรูปแบบใหม่อย่างน้อยหนึ่งคอลัมน์
            foreach (['ยอดรวมรายรับทั้งหมด รายบุคคล', 'รวมรายรับทางตรง', 'รวมรายรับทางอ้อม'] as $marker) {
                if (in_array($marker, $headers, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function parse(string $filePath): array
    {
        $sheet = IOFactory::load($filePath)->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, false);

        [$headerOffset, $indexes] = $this->resolveColumns($rows);
        $this->resolvedColumns = $indexes;

        $records = [];

        foreach (array_slice($rows, $headerOffset) as $row) {
            if ($this->isEmptyRow($row)) {
                continue;
            }

            $record = [];

            foreach ($indexes as $field => $index) {
                $record[$field] = $this->extractValue($row, $index, $field);
            }

            // ใส่ค่าว่างของ field ที่ไฟล์ไม่มีคอลัมน์ เพื่อให้ผู้เรียกไม่ต้องเช็ค key ทุกครั้ง
            foreach (self::OUTPUT_FIELDS as $field) {
                $record[$field] ??= ($indexes[$field] ?? null) === null
                    ? $this->extractValue($row, null, $field)
                    : $record[$field];
            }

            // แถวที่ไม่มีทั้งชื่อและเลขบัตร = แถวรวมยอด/แถวว่าง ไม่ใช่บุคลากร
            // ใช้ ?? '' เพราะคอลัมน์ชื่อ/เลขบัตรอาจไม่มีในไฟล์เลย
            if (trim((string) ($record['first_name'] ?? '')) === ''
                && trim((string) ($record['last_name'] ?? '')) === ''
                && trim((string) ($record['citizen_id'] ?? '')) === '') {
                continue;
            }

            $records[] = $this->withPeriod($record);
        }

        return $records;
    }

    /** จำนวนคอลัมน์ที่ต้องจับได้ขั้นต่ำเพื่อยอมรับว่าเป็นหัวตารางรูปแบบใหม่ */
    protected const MIN_MATCHED_COLUMNS = 6;

    /**
     * หาแถวหัวตาราง แล้วจับ field → column index
     *
     * เลือกแถวแรก ๆ ที่จับคอลัมน์ได้มากที่สุด (ไฟล์จริงอาจมีหัวตารางหลายแถว
     * หรือคอลัมน์ไม่ครบทุกช่อง จึงไม่ควรยึดเกณฑ์ "เกินครึ่ง" แบบตายตัว)
     *
     * @return array{0: int, 1: array<string, int>}
     */
    protected function resolveColumns(array $rows): array
    {
        $bestRow = -1;
        $bestIndexes = [];

        foreach ($rows as $i => $row) {
            if ($i > 20) {
                break;
            }

            $indexes = $this->matchRow($row);

            if (count($indexes) > count($bestIndexes)) {
                $bestRow = $i;
                $bestIndexes = $indexes;
            }
        }

        if (count($bestIndexes) >= self::MIN_MATCHED_COLUMNS) {
            return [$bestRow + 1, $bestIndexes];
        }

        // ไม่พบหัวตารางที่ตรงกัน — ปล่อยค่าว่างทั้งหมด ให้ผู้เรียกรายงานว่าไฟล์ไม่ถูกต้อง
        return [0, []];
    }

    /**
     * จับ field → column index ของแถวหัวตารางหนึ่งแถว
     *
     * @return array<string, int>
     */
    protected function matchRow(array $row): array
    {
        $indexes = [];

        foreach ($row as $index => $cell) {
            $name = self::normalizeHeader($cell);

            if ($name === '') {
                continue;
            }

            if (isset(self::COLUMN_MAP[$name])) {
                $indexes[self::COLUMN_MAP[$name]] = $index;

                continue;
            }

            if ($name === self::YEAR_COLUMN || $name === 'ปี (พ.ศ.)' || $name === 'ปีพ.ศ.') {
                $indexes['period_year'] = $index;

                continue;
            }

            if ($name === self::MONTH_COLUMN) {
                $indexes['period_month'] = $index;
            }
        }

        return $indexes;
    }

    /**
     * เติมงวด (ปีงบประมาณ) จาก ปี + เดือน ที่อ่านได้ในแถวนั้น
     *
     * ปีงบประมาณเริ่ม ต.ค. ของปี พ.ศ. ถัดไป → เดือน 10-12 ใช้ปีงบ = ปี+1
     */
    protected function withPeriod(array $record): array
    {
        $record += ['seq_number' => null, 'period_month' => null, 'period_year' => null];

        $year = is_numeric($record['period_year']) ? (int) $record['period_year'] : null;
        $month = $this->normalizeMonth($record['period_month']);

        $record['period_month'] = $month;
        $record['fiscal_year'] = ($year !== null && $month !== null)
            ? ($month >= 10 ? $year + 1 : $year)
            : null;

        return $record;
    }

    /**
     * แปลงชื่อเดือนไทย (หรือเลขเดือน) ให้เป็น 1-12
     */
    protected function normalizeMonth($value): ?int
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $name = self::normalizeHeader($value);

        if (isset(self::THAI_MONTHS[$name])) {
            return self::THAI_MONTHS[$name];
        }

        if (is_numeric($name)) {
            $month = (int) $name;

            return ($month >= 1 && $month <= 12) ? $month : null;
        }

        return null;
    }

    protected function extractValue(array $row, ?int $index, string $field)
    {
        $raw = $index === null ? null : ($row[$index] ?? null);

        if (in_array($field, self::TEXT_FIELDS, true)) {
            return $this->normalizeText($raw, $field);
        }

        if (in_array($field, self::INTEGER_FIELDS, true)) {
            $raw = $this->stripNumber($raw);

            return is_numeric($raw) ? (int) $raw : null;
        }

        if ($field === 'period_month') {
            return $this->normalizeMonth($raw);
        }

        if ($field === 'period_year') {
            $raw = $this->stripNumber($raw);

            return is_numeric($raw) ? (int) $raw : null;
        }

        if (in_array($field, self::MONEY_FIELDS, true)) {
            return $this->normalizeMoney($raw);
        }

        return $this->normalizeText($raw, $field);
    }

    /**
     * จำนวนเงิน: ลบ comma/ช่องว่าง/เครื่องหมายลบ แล้วแปลงเป็น float
     * ค่าว่าง เช่น " - " หรือ "" คือ 0
     */
    protected function normalizeMoney($value): float
    {
        $cleaned = $this->stripNumber($value);

        return is_numeric($cleaned) ? (float) $cleaned : 0.0;
    }

    /**
     * ทิ้งเครื่องหมายที่ใช้แทน "ไม่มีค่า" แล้วลบ comma/ช่องว่างออก
     * คืนค่าดิบถ้าไม่มีตัวเลขให้แปลง
     */
    protected function stripNumber($value): string
    {
        $text = trim((string) $value);

        // " - ", "-", "–", "—" = ไม่มีค่า
        if (in_array($text, ['-', '–', '—', '--'], true)) {
            return '';
        }

        return str_replace([',', ' ', "\u{00A0}", "\u{200B}"], '', $text);
    }

    protected function normalizeText($value, string $field): string
    {
        $text = trim((string) $value);

        if ($text === '' || $text === '-') {
            return '';
        }

        // เลขที่บัญชีบางไฟล์มีช่องว่างคั่น (521 0 00000 3) — ตัดออกให้เทียบข้ามไฟล์ได้
        if ($field === 'bank_account') {
            return str_replace(' ', '', $text);
        }

        // เลขบัตรประชาชนมีจุดคั่นหรือเครื่องหมายอื่นปนมาบ้าง
        if ($field === 'citizen_id') {
            return preg_replace('/\D/', '', $text);
        }

        return $text;
    }

    /**
     * ชื่อคอลัมน์ที่จับจากไฟล์ล่าสุดไม่ได้ แต่จำเป็นต่อฐานเงินสำรอง
     *
     * @return array<int, string>
     */
    public function missingReserveIncomeFields(): array
    {
        $missing = [];

        foreach (self::REQUIRED_COLUMNS as $header => $label) {
            if (! isset($this->resolvedColumns[self::COLUMN_MAP[$header]])) {
                $missing[] = $label;
            }
        }

        return $missing;
    }

    protected static function normalizeHeader($value): string
    {
        $name = mb_strtolower(trim((string) $value));
        $name = str_replace(['_', '-'], ' ', $name);
        // คงเว้นวรรคไว้ในคำที่มีเว้นอยู่แล้ว แต่ตัดช่องว่างซ้ำออก
        $name = preg_replace('/\s+/u', ' ', $name);

        return trim($name);
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
}