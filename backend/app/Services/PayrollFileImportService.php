<?php

namespace App\Services;

use App\Models\Import;
use App\Models\ReserveFundCalculation;
use App\Services\Parsers\NewFormatPayrollParser;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

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
     *     * @param  array<string, mixed>|null  $period  งวดที่ระบุจากUI (ถ้าไฟล์ไม่มีคอลัมน์งวด)
     * @return array{payroll: Import, employee: Import|null, employee_error: string|null,
     *               preview: array<int, array<string, mixed>>, total_rows: int,
     *               missing_income_fields: array<int, string>, import_ids: array<int, int>}
     */
    public function import(
        string $fullPath,
        string $storedPath,
        string $fileName,
        int $userId,
        ?array $period = null
    ): array {
        $analysis = $this->analyze($fullPath);

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
            // ทั้งสองฝั่งอ้างไฟล์เดียวกัน ตามด้วยหนึ่งรายการได้เลย
            'import_ids' => array_values(array_filter([
                $employeeImport?->id,
                $payrollImport->id,
            ])),
        ];
    }

    /**
     * งวดจากค่าที่ผู้ใช้เลือกในหน้าอัปโหลด (ถ้าเลือกไว้)
     *
     * ไฟล์รูปแบบใหม่มีคอลัมน์ปี/เดือนของแต่ละแถว จึงให้ ImportService
     * อ่านงวดจากไฟล์ก่อน และใช้ค่านี้เป็นตัวสำรอง
     *
     * @return array{fiscal_year:int, period_month:int, period_year:int}|null
     */
    public function periodFromRequest(?int $periodMonth, ?int $fiscalYear): ?array
    {
        if ($periodMonth === null) {
            return null;
        }

        $fiscalYear ??= ReserveFundCalculation::currentFiscalYear();

        return [
            'fiscal_year'  => $fiscalYear,
            'period_month' => $periodMonth,
            'period_year'  => ReserveFundCalculation::calendarYearOf($fiscalYear, $periodMonth),
        ];
    }
}