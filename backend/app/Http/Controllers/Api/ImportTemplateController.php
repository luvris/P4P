<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ImportTemplateService;
use App\Services\Parsers\NewFormatPayrollParser;
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
        protected ImportTemplateService $templates
    ) {}

    /**
     * GET /api/finance/imports/template
     * แบบฟอร์มเงินเดือน 39 คอลัมน์ — ใช้ได้ทั้งฝั่ง Finance และ HR
     */
    public function payroll(): StreamedResponse
    {
        return $this->stream(
            $this->templates->payrollTemplate(),
            'แบบฟอร์มนำเข้าข้อมูลเงินเดือน.xlsx'
        );
    }

    /**
     * รายการคอลัมน์ของไฟล์ต้นแบบ (หน้าเว็บใช้แสดงคำอธิบาย)
     */
    public function columns(): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'data' => [
                'payroll' => [
                    'title'            => 'แบบฟอร์มนำเข้าข้อมูลเงินเดือน',
                    'columns'          => NewFormatPayrollParser::TEMPLATE_COLUMNS,
                    'required'         => NewFormatPayrollParser::CRITICAL_COLUMNS,
                    'reserve_base'     => NewFormatPayrollParser::RESERVE_BASE_COLUMNS,
                    'months'           => NewFormatPayrollParser::TEMPLATE_MONTHS,
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
