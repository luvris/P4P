<?php

namespace App\Services;

use App\Models\Import;
use App\Models\ReserveFundCalculation;
use App\Services\Parsers\NewFormatPayrollParser;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * นำเข้าไฟล์เงินเดือนครั้งเดียว ได้ทั้งแถวเงินเดือนและทะเบียนบุคลากร
 *
 * ไฟล์เดียวกันถูกใช้ได้ทั้งสองทาง เพราะคอลัมน์บุคลากร (ชื่อ ตำแหน่ง เลขบัตร)
 * อยู่ในไฟล์ payroll อยู่แล้ว — การอ่านแยกสอง service ทำให้ผู้ใช้ต้องอัปโหลด
 * ไฟล์เดิมสองครั้ง และข้อมูลสองฝั่งอาจคลาดกันได้
 *
 * ลำดับการเขียนสำคัญ: เขียนทะเบียนบุคลากรก่อน แล้วค่อยเขียน payrolls
 * เพราะ ImportService ต้องใช้ทะเบียนบุคลากรเดิมในการเดาเลขบัตรประชาชน
 * จากชื่อ-นามสกุล (ไฟล์รูปแบบใหม่ไม่มีคอลัมน์เลขบัตร) ถ้าเขียน payrolls
 * ก่อน คนที่ยังไม่มีในทะเบียนจะกลายเป็นแถวที่ผูกไม่ได้ทั้งที่แค่ยังไม่ได้ลงทะเบียน
 */
class PayrollFileImportService
{
    public function __construct(
        protected ImportService $importService,
        protected NewFormatEmployeeImportService $employeeService
    ) {}

    /**
     * อ่านไฟล์แล้วคืนข้อมูลทั้งสองฝั่ง ใช้ตอน preview (ยังไม่เขียนลงฐาน)
     *
     * @return array{
     *     rows: array<int, array<string, mixed>>,
     *     payroll_preview: array<int, array<string, mixed>>,
     *     payroll_total_rows: int,
     *     employee: array{preview: array<string, mixed>, warnings: array<int, string>, warning_count: int},
     *     warnings: array<int, string>,
     *     warning_count: int
     * }
     */
    public function analyze(string $fullPath): array
    {
        // อ่านไฟล์ครั้งเดียว แล้วใช้ทั้งสองฝั่ง — ไฟล์จริงหลายพันแถว อ่านสองรอบช้ามาก
        $rows = (new NewFormatPayrollParser())->parse($fullPath);

        $parsedEmployees = $this->employeeService->summarize($rows);
        $employeeResult = $this->employeeService->preview($parsedEmployees['data'], $parsedEmployees['warnings']);

        return [
            'rows'              => $rows,
            'payroll_preview'   => array_slice($rows, 0, 10),
            'payroll_total_rows'=> count($rows),
            'employee'          => $employeeResult,
            'warnings'          => $parsedEmployees['warnings'],
            'warning_count'     => count($parsedEmployees['warnings']),
        ];
    }

    /**
     * เขียนข้อมูลทั้งสองฝั่งลงฐาน คืนผลลัพธ์ของแต่ละฝั่งแยกให้ชัดเจน
     *
     * ฝั่งทะเบียนบุคลากรล้มเหลวได้โดยไม่ทำให้ฝั่ง payroll ล้ม — เพราะเป็นคนละตาราง
     * ถ้าทะเบียนบุคลากรเขียนไม่ได้ ผู้ใช้ยังต้องได้แถวเงินเดือนครบ
     *     * @param  array<string, mixed>|null  $period  งวดที่ผู้ใช้เลือกจากหน้าอัปโหลด
     * @return array{payroll: Import, employee: Import|null, employee_error: string|null,
     *               preview: array<int, array<string, mixed>>, total_rows: int,
     *               missing_income_fields: array<int, string>, scope_warnings: array<int, string>,
     *               import_ids: array<int, int>}
     */
    public function import(
        string $fullPath,
        string $storedPath,
        string $fileName,
        int $userId,
        ?array $period = null
    ): array {
        $analysis = $this->analyze($fullPath);

        // ตรวจขอบเขตงวดก่อนเขียนอะไรลงฐาน — ถ้าเดาไม่ได้ต้องหยุด ไม่ใช่เดาให้
        $scopeWarnings = $period !== null
            ? $this->checkScope($analysis['rows'], $period)
            : [];

        // ฝั่งทะเบียนบุคลากรก่อน — payrolls ต้องเดาเลขบัตรจากทะเบียนที่อัปเดตแล้ว
        $employeeImport = null;
        $employeeError = null;

        if ($analysis['employee']['preview']['total_rows'] > 0) {
            try {
                $employeeImport = $this->employeeService->import(
                    $this->employeeService->summarize($analysis['rows'])['data'],
                    $fileName,
                    $storedPath,
                    $userId
                );
            } catch (\Throwable $e) {
                // ไม่ throw — ให้ฝั่ง payroll ยังทำงานต่อได้ แล้วรายงานกลับไปทาง UI
                $employeeError = $e->getMessage();

                Log::error('Unified payroll import: บันทึกทะเบียนบุคลากรไม่สำเร็จ', [
                    'file_name' => $fileName,
                    'error'     => $employeeError,
                ]);
            }
        }

        $parser = new NewFormatPayrollParser();
        $parser->parse($fullPath);   // ให้ parser รู้คอลัมน์ที่ resolve ได้ (สำหรับ missingReserveIncomeFields)

        $payrollImport = $this->importService->process(
            $fullPath,
            $fileName,
            $userId,
            $parser,
            $period,
            $storedPath
        );

        return [
            'payroll'        => $payrollImport,
            'employee'       => $employeeImport,
            'employee_error' => $employeeError,
            'preview'        => $analysis['payroll_preview'],
            'total_rows'     => $analysis['payroll_total_rows'],
            'missing_income_fields' => $parser->missingReserveIncomeFields(),
            'scope_warnings' => $scopeWarnings,
            // ทั้งสองฝั่งอ้างไฟล์เดียวกัน ตามด้วยหนึ่งรายการได้เลย
            'import_ids' => array_values(array_filter([
                $employeeImport?->id,
                $payrollImport->id,
            ])),
        ];
    }

    /**
     * ตรวจว่าขอบเขตงวดที่ผู้ใช้เลือกตรงกับงวดที่อยู่ในไฟล์จริง
     *
     * ไฟล์ที่มีคอลัมน์ปี/เดือนของตัวเอง จะใช้งวดของตัวเองเสมอ
     * ค่าที่ผู้ใช้เลือกจึงเป็นการยืนยัน/กำกวม ไม่ใช่การบังคับ
     *
     * กรณีที่เดาไม่ได้ (เลือกหลายงวดหรือทั้งปีงบ แต่แถวในไฟล์ไม่มีเดือน)
     * จะไม่เดาให้ เพราะงวดผิดแค่เดือนเดียวทำให้ยอดเงินสำรองผิดทั้งปี
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $period
     * @return array<int, string>  คำเตือนที่ควรโชว์ผู้ใช้
     *
     * @throws ValidationException  เมื่อเลือกขอบเขตที่ระบบเดาไม่ได้
     */
    protected function checkScope(array $rows, array $period): array
    {
        $months = array_values(array_unique(array_filter(
            array_map('intval', $period['period_months'] ?? [])
        )));
        $fiscalYear = (int) ($period['fiscal_year'] ?? 0);

        $withoutMonth = 0;
        $outOfScope = [];

        foreach ($rows as $row) {
            if (($row['period_month'] ?? null) === null) {
                $withoutMonth++;

                continue;
            }

            $rowMonth = (int) $row['period_month'];
            $rowFiscalYear = (int) ($row['fiscal_year'] ?? $row['period_year'] ?? 0);

            $inScope = $months === []
                ? $rowFiscalYear === $fiscalYear
                : in_array($rowMonth, $months, true) && $rowFiscalYear === $fiscalYear;

            if (! $inScope) {
                $outOfScope[] = $rowMonth . '/' . ($row['period_year'] ?? $rowFiscalYear);
            }
        }

        // เลือกงวดเดียว → ระบบเติมให้แถวที่ไม่มีเดือนได้ (งวดสำรองตามเดิม)
        // ส่วนแบบหลายงวด/ทั้งปีงบ ($months ว่าง = ทั้งปีงบ) ต้องเดาไม่ได้ จึงเข้มขึ้น
        if (count($months) === 1) {
            return $outOfScope === []
                ? []
                : [sprintf(
                    'บางแถวในไฟล์เป็นงวดนอกที่เลือก (%s) — ระบบใช้งวดของแต่ละแถวตามไฟล์',
                    implode(', ', array_unique(array_slice($outOfScope, 0, 5)))
                )];
        }

        // เลือกหลายงวดหรือทั้งปีงบ — แถวที่ไม่มีเดือนคือเดาไม่ได้
        if ($withoutMonth > 0) {
            throw ValidationException::withMessages([
                'period_months' => [sprintf(
                    'ไฟล์มี %d แถวที่ไม่ได้ระบุเดือน จึงยังบอกไม่ได้ว่าควรอยู่งวดไหน — '
                    . 'ให้กรอกคอลัมน์ปี/เดือนของแต่ละแถวให้ครบ หรือเลือกอัปโหลดแบบงวดเดียวแทน',
                    $withoutMonth
                )],
            ]);
        }

        if ($outOfScope !== []) {
            throw ValidationException::withMessages([
                'period_months' => [sprintf(
                    'ไฟล์มีงวดที่อยู่นอกขอบเขตที่เลือก (%s) — ตรวจว่าเลือกปีงบ/เดือนถูกปีหรือยัง',
                    implode(', ', array_unique(array_slice($outOfScope, 0, 5)))
                )],
            ]);
        }

        return [];
    }

    /**
     * งวดจากค่าที่ผู้ใช้เลือกในหน้าอัปโหลด
     *
     * ผู้ใช้เลือกได้ 3 แบบ:
     *   month  = งวดเดียว (เดือนเดียว) → ใช้เป็นงวดสำรองของแถวที่ไม่มีเดือน
     *   months = หลายงวด           → ใช้ตรวจว่าไฟล์ตรงกับงวดที่เลือก
     *   year   = ทั้งปีงบประมาณ        → ใช้ตรวจว่าทุกงวดอยู่ใน ต.ค.–ก.ย. ของปีงบนั้น
     *
     * ไฟล์รูปแบบใหม่มีคอลัมน์ปี/เดือนของแต่ละแถว งวดของแต่ละแถวจึงเป็นหลักเสมอ
     *
     * @param  array<int, int>|null  $months  เดือนที่เลือก (เลข 1-12)
     * @return array{fiscal_year:int, period_month:?int, period_months:array<int,int>, period_year:?int}|null
     */
    public function periodFromRequest(?array $months, ?int $fiscalYear): ?array
    {
        $months = array_values(array_unique(array_map('intval', $months ?? [])));
        sort($months);

        if ($months === [] && $fiscalYear === null) {
            return null;
        }

        $fiscalYear ??= ReserveFundCalculation::currentFiscalYear();
        $single = count($months) === 1 ? $months[0] : null;

        return [
            'fiscal_year'    => $fiscalYear,
            'period_month'   => $single,
            'period_months'  => $months,
            'period_year'    => $single === null
                ? null
                : ReserveFundCalculation::calendarYearOf($fiscalYear, $single),
        ];
    }

    /** เดือน 10-12 ของปีก่อนหน้า + เดือน 1-9 ของปีเดียวกัน = ปีงบหนึ่งปี */
    public static function fiscalYearMonths(int $fiscalYear): array
    {
        $months = [];

        foreach ([10, 11, 12] as $month) {
            $months[] = ['month' => $month, 'year' => $fiscalYear - 1];
        }

        foreach (range(1, 9) as $month) {
            $months[] = ['month' => $month, 'year' => $fiscalYear];
        }

        return $months;
    }
}