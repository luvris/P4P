<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ImportTemplateService;
use App\Services\Parsers\NewFormatPayrollParser;
use App\Services\PayrollExportService;
use App\Services\PayrollExtraColumnService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * ดาวน์โหลดไฟล์ต้นแบบสำหรับนำเข้าข้อมูล
 *
 * ผู้ใช้ดาวน์โหลดแบบฟอร์ม → กรอก → อัปโหลดกลับเข้าระบบ
 * ไฟล์ที่สร้างได้ผ่านการตรวจด้วย parser ตัวจริงเสมอ จึงไม่เกิด error
 */
class ImportTemplateController extends Controller
{
    public function __construct(
        protected ImportTemplateService $templates,
        protected PayrollExtraColumnService $extraColumns,
        protected PayrollExportService $exports
    ) {}

    /**
     * GET /api/imports/template
     * แบบฟอร์มเงินเดือน 39 คอลัมน์ + คอลัมน์ที่ผู้ใช้เพิ่มไว้
     */
    public function payroll(): StreamedResponse
    {
        return $this->stream(
            $this->templates->payrollTemplate(),
            'แบบฟอร์มนำเข้าข้อมูลเงินเดือน.xlsx'
        );
    }

    /**
     * GET /api/imports/export?period_month=1&period_year=2569
     * GET /api/imports/export?fiscal_year=2569
     * ไฟล์ข้อมูลจริง หัวตารางเดียวกับแบบฟอร์ม แต่แถวแรกเป็นข้อมูลจริงทันที
     *
     * เลือกได้อย่างใดอย่างหนึ่ง: งวดเดียว หรือทั้งปีงบประมาณ (ต.ค. – ก.ย.)
     */
    public function export(Request $request): StreamedResponse
    {
        $validated = $request->validate([
            'period_month' => ['nullable', 'integer', 'between:1,12'],
            'period_year'  => ['nullable', 'integer', 'between:2500,3000'],
            'fiscal_year'  => ['nullable', 'integer', 'between:2500,3000'],
        ]);

        $month = isset($validated['period_month']) ? (int) $validated['period_month'] : null;
        $year = isset($validated['period_year']) ? (int) $validated['period_year'] : null;
        $fiscalYear = isset($validated['fiscal_year']) ? (int) $validated['fiscal_year'] : null;

        // ไม่ระบุอะไรเลย = ยังไม่ได้เลือกว่าจะเอางวดไหนหรือปีไหน
        if ($month === null && $fiscalYear === null) {
            throw ValidationException::withMessages([
                'period_month' => ['ระบุงวดที่จะ export หรือปีงบประมาณที่จะ export'],
                'period_year'  => ['ระบุงวดที่จะ export หรือปีงบประมาณที่จะ export'],
            ]);
        }

        // ระบุมาทั้งสองแบบ = กำกวม ผู้ใช้อาจจะได้ไฟล์ที่ไม่ตรงใจ
        if ($month !== null && $fiscalYear !== null) {
            throw ValidationException::withMessages([
                'fiscal_year' => ['เลือกได้อย่างใดอย่างหนึ่งระหว่างรายงวดกับทั้งปีงบประมาณ'],
            ]);
        }

        if ($fiscalYear !== null) {
            return $this->stream(
                $this->exports->exportFiscalYear($fiscalYear)['spreadsheet'],
                $this->exports->fiscalYearFileName($fiscalYear)
            );
        }

        // เลือกงวดแล้วต้องมีปีมาด้วย ไม่งั้นไม่รู้ว่าจะเอางวดของปีไหน
        if ($year === null) {
            throw ValidationException::withMessages([
                'period_year' => ['ระบุปีของงวดที่จะ export'],
            ]);
        }

        return $this->stream(
            $this->exports->export($month, $year)['spreadsheet'],
            $this->exports->fileName($month, $year)
        );
    }

    /**
     * GET /api/imports/periods
     * งวดและปีงบประมาณที่มีข้อมูลในระบบ — ปุ่ม export ใช้เป็นตัวเลือก
     */
    public function periods(): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'data'  => $this->exports->periods(),
            'years' => $this->exports->fiscalYears(),
        ]);
    }

    /**
     * รายการคอลัมน์ของไฟล์ต้นแบบ (หน้าเว็บใช้แสดงคำอธิบาย)
     */
    public function columns(): \Illuminate\Http\JsonResponse
    {
        $base = NewFormatPayrollParser::TEMPLATE_COLUMNS;

        return response()->json([
            'data' => [
                'payroll' => [
                    'title'        => 'แบบฟอร์มนำเข้าข้อมูลเงินเดือน',
                    'columns'      => $this->extraColumns->columnsWithExtras($base),
                    'base_columns' => $base,
                    'required'     => NewFormatPayrollParser::CRITICAL_COLUMNS,
                    'reserve_base' => NewFormatPayrollParser::RESERVE_BASE_COLUMNS,
                    'months'       => NewFormatPayrollParser::TEMPLATE_MONTHS,
                    // คอลัมน์ที่ผู้ใช้เพิ่มเอง พร้อม key และชนิด สำหรับแสดงผล
                    'extras'       => $this->extraColumns->metadata(),
                ],
            ],
        ]);
    }

    /**
     * ส่งไฟล์ xlsx ออกไปโดยไม่ต้องเขียนไฟล์ชั่วคราว
     *
     * ใช้ php://temp แทน sys_get_temp_dir() เพราะเซิร์ฟเวอร์บางเครื่อง
     * (รวมถึงตอนนำเข้าไฟล์) สร้างไฟล์ในโฟลเดอร์ชั่วคราวไม่ได้
     */
    protected function stream($spreadsheet, string $fileName): StreamedResponse
    {
        // ส่งเป็นชื่อไฟล์ไทยแบบ UTF-8 ที่ถูกต้อง (RFC 5987)
        $encoded = rawurlencode($fileName);

        return response()->streamDownload(function () use ($spreadsheet) {
            $handle = fopen('php://temp', 'r+');
            (new XlsxWriter($spreadsheet))->save($handle);
            rewind($handle);
            fpassthru($handle);
            fclose($handle);
        }, $fileName, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"import-template.xlsx\"; filename*=UTF-8''{$encoded}",
        ]);
    }
}
