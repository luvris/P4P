<?php

namespace App\Services;

use App\Models\Payroll;
use App\Models\Employee;
use App\Models\PayrollExtraColumn;
use App\Services\Parsers\NewFormatPayrollParser;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * ส่งออกข้อมูลเงินเดือนเป็นไฟล์ที่มีโครงเหมือนแบบฟอร์มนำเข้าข้อมูล
 *
 * จุดประสงค์คือให้ฝ่ายการเงิน/บุคลากรได้ไฟล์ที่เปิดแก้ต่อได้เลย
 * โดยไม่ต้องไปหาไฟล์เดิมที่เคยอัปโหลด และไม่ต้องเดาว่าคอลัมน์ไหนอยู่ตรงไหน
 * เพราะหัวตารางยึดจากตารางคอลัมน์เดียวกับที่ใช้สร้างแบบฟอร์ม (รวมคอลัมน์ที่ผู้ใช้เพิ่ม
 * และตำแหน่งที่เลือกไว้) แถวแรกเป็นข้อมูลจริงทันที ไม่มีแถวตัวอย่างปะปน
 */
class PayrollExportService
{
    /** คอลัมน์ในไฟล์ต้นแบบที่ไม่ได้เก็บไว้ใน payrolls จึงไม่มีค่าจะส่งออก */
    protected const UNSTORED_COLUMNS = ['เลขที่บัญชี.1'];

    /**
     * คอลัมน์เพิ่มเติมที่เป็นข้อมูลระดับคน → key ของคอลัมน์ → ความสัมพันธ์ในทะเบียนบุคลากร
     *
     * ภารกิจ/กลุ่มงาน/งาน เป็นข้อมูลของตัวบุคลากร ไม่เปลี่ยนทุกงวด
     * ถ้ายึดแค่ไฟล์เงินเดือน ค่าจะหายไปทุกครั้งที่นำเข้าจากไฟล์ที่ไม่ได้กรอกคอลัมน์เหล่านี้
     */
    protected const EMPLOYEE_ATTRIBUTES = [
        'duty'       => 'duty',    // duty       → employees.duty_id
        'work_group' => 'group',   // work_group → employees.group_id
        'work'       => 'work',    // work       → employees.work_id
    ];

    /** เดือนแรกของปีงบประมาญไทย — ปีงบหนึ่งครอบคลุม ต.ค. ถึง ก.ย. */
    public const FISCAL_START_MONTH = 10;

    public function __construct(
        protected ImportTemplateService $templates,
        protected PayrollExtraColumnService $extraColumns
    ) {}

    /**
     * งวดที่มีข้อมูลในระบบ เรียงงวดใหม่สุดก่อน
     *
     * ใช้เป็นตัวเลือกงวดของปุ่ม export — ถ้าให้เลือกงวดเองต้องมีข้อมูลจริงเสมอ
     *
     * @return array<int, array{period_year: int, period_month: int, label: string, rows: int}>
     */
    public function periods(): array
    {
        return Payroll::query()
            // ชื่อ alias ต้องเลี่ยงคำว่า reserved ของ MariaDB (rows ใช้ไม่ได้)
            ->selectRaw('period_year, period_month, COUNT(*) as row_count')
            ->groupBy('period_year', 'period_month')
            ->orderByDesc('period_year')
            ->orderByDesc('period_month')
            ->get()
            ->map(fn ($row) => [
                'period_year'  => (int) $row->period_year,
                'period_month' => (int) $row->period_month,
                'label'        => $this->periodLabel((int) $row->period_month, (int) $row->period_year),
                'rows'         => (int) $row->row_count,
            ])
            ->values()
            ->all();
    }

    /**
     * ปีงบประมาณที่มีข้อมูล เรียงใหม่สุดก่อน พร้อมจำนวนงวดและจำนวนคน-งวด
     *
     * ไฟล์เงินเดือนแยกเป็นรายงวด ผู้ใช้จึงต้องการดูข้อมูลทั้งปีงบในไฟล์เดียว
     * ปีงบที่นำเสนอคำนวณจาก ปี/เดือน ที่เก็บไว้กับแต่ละแถว ไม่ใช้ fiscal_year
     * ที่อาจเก่ากว่าข้อมูลที่แก้ไขในไฟล์ต้นทาง
     *
     * @return array<int, array{fiscal_year: int, label: string, periods: int, rows: int}>
     */
    public function fiscalYears(): array
    {
        $grouped = Payroll::query()
            ->selectRaw('period_year, period_month, COUNT(*) as row_count')
            ->groupBy('period_year', 'period_month')
            ->get();

        $years = [];

        foreach ($grouped as $row) {
            $month = (int) $row->period_month;
            $year = (int) $row->period_year;
            $fiscalYear = $this->fiscalYearOf($month, $year);

            $years[$fiscalYear] ??= ['fiscal_year' => $fiscalYear, 'periods' => 0, 'rows' => 0];
            $years[$fiscalYear]['periods']++;
            $years[$fiscalYear]['rows'] += (int) $row->row_count;
        }

        krsort($years);

        return array_values(array_map(function (array $year) {
            $year['label'] = $this->fiscalYearLabel($year['fiscal_year']);

            return $year;
        }, $years));
    }

    /**
     * ไฟล์ข้อมูลทั้งปีงบประมาณ (ต.ค. ถึง ก.ย.) เป็นชีตเดียว
     *
     * แต่ละแถวยังมีคอลัมน์ ปี/เดือน ของงวดตัวเอง จึงนำกลับเข้าระบบได้เหมือนไฟล์งวดเดียว
     *
     * @return array{spreadsheet: Spreadsheet, rows: int, label: string}
     */
    public function exportFiscalYear(int $fiscalYear): array
    {
        $payrolls = $this->rowsForFiscalYear($fiscalYear);

        return [
            'spreadsheet' => $this->templates->payrollExport($this->valuesFor($payrolls)),
            'rows'        => $payrolls->count(),
            'label'       => $this->fiscalYearLabel($fiscalYear),
        ];
    }

    /**
     * แถวทั้งปีงบ เรียงตามรอบปีงบจริง (ต.ค. → ก.ย.) ไม่ใช่ ม.ค. → ธ.ค.
     *
     * @return Collection<int, Payroll>
     */
    public function rowsForFiscalYear(int $fiscalYear): Collection
    {
        return Payroll::query()
            ->where(function ($query) use ($fiscalYear) {
                $query->where(function ($query) use ($fiscalYear) {
                    $query->where('period_year', $fiscalYear - 1)
                        ->where('period_month', '>=', self::FISCAL_START_MONTH);
                })->orWhere(function ($query) use ($fiscalYear) {
                    $query->where('period_year', $fiscalYear)
                        ->where('period_month', '<', self::FISCAL_START_MONTH);
                });
            })
            ->orderBy('seq_number')
            ->orderBy('id')
            ->get()
            ->sortBy(fn ($payroll) => [
                $this->fiscalMonthIndex((int) $payroll->period_month),
                (int) $payroll->seq_number,
                (int) $payroll->id,
            ])
            ->values();
    }

    /**
     * ปีงบประมาณของงวดเดือนนั้น
     *
     * ปีงบประมาณไทยตั้งชื่อตามปีที่สิ้นสุด เช่น ปีงบ 2569 คือ ต.ค. 2568 – ก.ย. 2569
     * งวด ต.ค.–ธ.ค. จึงนับเป็นปีงบถัดไป ส่วน ม.ค.–ก.ย. นับเป็นปีงบเดียวกับปีปฏิทิน
     */
    public function fiscalYearOf(int $periodMonth, int $periodYear): int
    {
        return $periodMonth >= self::FISCAL_START_MONTH ? $periodYear + 1 : $periodYear;
    }

    public function fiscalYearLabel(int $fiscalYear): string
    {
        return "ปีงบประมาณ {$fiscalYear}";
    }

    /**
     * ลำดับเดือนในรอบปีงบ ต.ค. = 1 ... ก.ย. = 12
     */
    protected function fiscalMonthIndex(int $periodMonth): int
    {
        return $periodMonth >= self::FISCAL_START_MONTH
            ? $periodMonth - self::FISCAL_START_MONTH + 1
            : $periodMonth + 13 - self::FISCAL_START_MONTH;
    }

    /**
     * ไฟล์ข้อมูลงวดเดียว
     *
     * @return array{spreadsheet: Spreadsheet, rows: int, label: string}
     */
    public function export(int $periodMonth, int $periodYear): array
    {
        $payrolls = $this->rows($periodMonth, $periodYear);
        $label = $this->periodLabel($periodMonth, $periodYear);

        $values = $this->valuesFor($payrolls);

        return [
            'spreadsheet' => $this->templates->payrollExport($values),
            'rows'        => $payrolls->count(),
            'label'       => $label,
        ];
    }

    /**
     * แถวเงินเดือนของงวดนั้น เรียงตามลำดับที่จัดไว้ในไฟล์
     *
     * @return Collection<int, Payroll>
     */
    public function rows(int $periodMonth, int $periodYear): Collection
    {
        return Payroll::query()
            ->where('period_month', $periodMonth)
            ->where('period_year', $periodYear)
            ->orderBy('seq_number')
            ->orderBy('id')
            ->get();
    }

    /**
     * ชื่อไฟล์แบบ งวด-ปี เช่น "ข้อมูลเงินเดือน-พ.ค.-2569.xlsx"
     */
    public function fileName(int $periodMonth, int $periodYear): string
    {
        $month = NewFormatPayrollParser::TEMPLATE_MONTHS[$periodMonth - 1] ?? $periodMonth;

        return "ข้อมูลเงินเดือน-{$month}-{$periodYear}.xlsx";
    }

    /**
     * ชื่อไฟล์แบบ ปีงบประมาณ เช่น "ข้อมูลเงินเดือน-ปีงบประมาณ-2569.xlsx"
     */
    public function fiscalYearFileName(int $fiscalYear): string
    {
        return "ข้อมูลเงินเดือน-ปีงบประมาณ-{$fiscalYear}.xlsx";
    }

    public function periodLabel(int $periodMonth, int $periodYear): string
    {
        $month = NewFormatPayrollParser::TEMPLATE_MONTHS[$periodMonth - 1] ?? (string) $periodMonth;

        return "{$month} {$periodYear}";
    }

    /**
     * ค่าของแต่ละแถว เรียงตามคอลัมน์ของไฟล์พอดี
     *
     * @param  Collection<int, Payroll>  $payrolls
     * @return array<int, array<int, mixed>>
     */
    protected function valuesFor(Collection $payrolls): array
    {
        $columns = $this->extraColumns->columnsWithExtras(NewFormatPayrollParser::TEMPLATE_COLUMNS);
        $extraKeys = $this->extraKeysByName();
        $registry = $this->employeeAttributes($payrolls);

        $rows = [];
        $sequence = 0;
        $currentPeriod = null;

        foreach ($payrolls as $payroll) {
            $extra = $this->extraData($payroll);
            $values = [];

            // นับแถวทีละแถว ไม่ใช่ทีละคอลัมน์ ไม่งั้นเลขลำดับจะเพี้ยนตามจำนวนคอลัมน์
            // ไล่ใหม่ต่องวด เพราะไฟล์รายปีรวมหลายงวดไว้ในชีตเดียว
            $periodKey = $payroll->period_year . '-' . $payroll->period_month;

            if ($periodKey !== $currentPeriod) {
                $sequence = 0;
                $currentPeriod = $periodKey;
            }

            $sequence++;

            foreach ($columns as $column) {
                $values[] = $this->valueFor($column, $payroll, $extra, $extraKeys, $sequence, $registry);
            }

            $rows[] = $values;
        }

        return $rows;
    }

    /**
     * ค่าของคอลัมน์หนึ่งช่อง — ชื่อคอลัมน์เป็นคำสั่งว่าจะหยิบค่าจากฟิลด์ไหน
     *
     * @param  array<string, string>  $extraKeys
     */
    protected function valueFor(
        string $column,
        Payroll $payroll,
        array $extra,
        array $extraKeys,
        int $sequence,
        array $registry = []
    ): mixed {
        // งวดในไฟล์คือ ปี พ.ศ. + ชื่อเดือนไทย ไม่ใช่งวดที่ระบบคำนวณไว้
        if ($column === 'ปี') {
            return (int) $payroll->period_year;
        }

        if ($column === 'เดือน') {
            return NewFormatPayrollParser::TEMPLATE_MONTHS[(int) $payroll->period_month - 1] ?? '';
        }

        if ($column === 'ลำดับที่') {
            // ไล่ใหม่เป็น 1..N ตามลำดับไฟล์ ไม่ใช้ค่าที่เก็บไว้
            // เพราะค่าที่เก็บมาคือลำดับในไฟล์ต้นทาง ซึ่งซ้ำกันข้ามงวดและข้ามไฟล์
            return $sequence;
        }

        if (in_array($column, self::UNSTORED_COLUMNS, true)) {
            return '';
        }

        // รหัสตัวระบุ (เลขบัตรประชาชน/เลขบัญชี/เลขที่ตำแหน่ง) ต้องเป็นข้อความ
        // ถ้าปล่อยเป็นตัวเลข Excel จะแสดงเลข 13 หลักเป็น 3.5299E+12
        if (in_array($column, NewFormatPayrollParser::TEXT_COLUMNS, true)) {
            $textField = NewFormatPayrollParser::fieldForColumn($column);

            return $textField === null ? '' : trim((string) $payroll->{$textField});
        }

        // คอลัมน์ที่ผู้ใช้ประกาศเพิ่ม — ค่าเก็บแยกใน extra_data
        if (isset($extraKeys[$column])) {
            $key = $extraKeys[$column];
            $fromFile = $extra[$key] ?? null;

            // ค่าที่กรอกในไฟล์งวดนั้นสำคัญกว่า ถ้ามีให้ใช้ค่านั้นก่อน
            if ($fromFile !== null && trim((string) $fromFile) !== '') {
                return $fromFile;
            }

            // ไม่ได้กรอกในไฟล์ — คอลัมน์ที่เป็นข้อมูลระดับคนต้องดึงจากทะเบียนบุคลากร
            // ไม่งั้นข้อมูลที่มีอยู่แล้วจะหายไปทุกครั้งที่ export
            return trim((string) ($registry[$payroll->citizen_id][$key] ?? '')) === ''
                ? ''
                : $registry[$payroll->citizen_id][$key];
        }

        $field = NewFormatPayrollParser::fieldForColumn($column);

        if ($field === null) {
            return '';
        }

        $value = $payroll->{$field};

        return is_numeric($value) ? 0 + $value : ($value ?? '');
    }

    /**
     * ชื่อคอลัมน์ที่ประกาศเพิ่ม → key ที่เก็บค่าไว้ใน extra_data
     *
     * @return array<string, string>
     */
    protected function extraKeysByName(): array
    {
        $keys = [];

        foreach (PayrollExtraColumn::active() as $column) {
            $keys[$column->name] = $column->key;
        }

        return $keys;
    }

    /**
     * ค่าคอลัมน์เพิ่มเติมระดับคน ของทุกคนในไฟล์ เตรียมไว้ครั้งเดียวต่อการ export
     *
     * ภารกิจ / กลุ่มงาน / งาน เป็นข้อมูลของตัวบุคลากร ไม่เปลี่ยนทุกงวด
     * ถ้ายึดแค่ไฟล์เงินเดือน ค่าจะหายไปเมื่อนำเข้าจากไฟล์ที่ไม่ได้กรอกคอลัมน์เหล่านี้
     * แม้ทะเบียนบุคลากรยังมีข้อมูลอยู่ครบ
     *
     * @return array<string, array<string, string|null>>
     */
    protected function employeeAttributes(Collection $payrolls): array
    {
        $declared = array_values($this->extraKeysByName());
        $needed = array_values(array_intersect(array_keys(self::EMPLOYEE_ATTRIBUTES), $declared));

        if ($needed === []) {
            return [];
        }

        $citizenIds = $payrolls->pluck('citizen_id')
            ->map(fn ($id) => trim((string) $id))
            ->filter()
            ->unique()
            ->values();

        if ($citizenIds->isEmpty()) {
            return [];
        }

        $map = [];

        foreach (Employee::with(array_values(self::EMPLOYEE_ATTRIBUTES))
            ->whereIn('citizen_id', $citizenIds)
            ->get() as $employee) {
            $attributes = [];

            foreach ($needed as $key) {
                $attributes[$key] = $employee->{self::EMPLOYEE_ATTRIBUTES[$key]}?->name;
            }

            $map[$employee->citizen_id] = $attributes;
        }

        return $map;
    }

    /**
     * ค่าคอลัมน์เพิ่มเติมของแถวนั้น (เก็บเป็น JSON ในฐาน)
     *
     * @return array<string, mixed>
     */
    protected function extraData(Payroll $payroll): array
    {
        $extra = $payroll->extra_data;

        if (is_string($extra)) {
            $extra = json_decode($extra, true);
        }

        return is_array($extra) ? $extra : [];
    }
}