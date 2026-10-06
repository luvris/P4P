<?php

namespace App\Services;

use App\Models\Payroll;
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

        $rows = [];
        $sequence = 0;

        foreach ($payrolls as $payroll) {
            $extra = $this->extraData($payroll);
            $values = [];

            // นับแถวทีละแถว ไม่ใช่ทีละคอลัมน์ ไม่งั้นเลขลำดับจะเพี้ยนตามจำนวนคอลัมน์
            $sequence++;

            foreach ($columns as $column) {
                $values[] = $this->valueFor($column, $payroll, $extra, $extraKeys, $sequence);
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
        int $sequence
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

        // คอลัมน์ที่ผู้ใช้ประกาศเพิ่ม — ค่าเก็บแยกใน extra_data
        if (isset($extraKeys[$column])) {
            return $extra[$extraKeys[$column]] ?? '';
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