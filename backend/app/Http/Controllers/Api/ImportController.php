<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Import;
use App\Models\Payroll;
use App\Models\ReserveFundCalculation;
use App\Services\ImportService;
use App\Services\Parsers\NewFormatPayrollParser;
use App\Services\Parsers\XlsxParser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ImportController extends Controller
{
    public function __construct(
        protected ImportService $importService
    ) {}

    /**
     * อัปโหลดไฟล์
     */
    /**
     * เลือก parser ตามรูปแบบไฟล์จริง
     *
     * ไฟล์รูปแบบใหม่ (39 คอลัมน์) ต้องใช้ NewFormatPayrollParser ไม่ใช่ XlsxParser เดิม
     * มิฉะนั้น preview จะอ่านเลขบัตรประชาชนไม่ได้ และแสดงผลผิดคอลัมน์
     */
    protected function resolveParser(string $extension, string $fullPath)
    {
        if (in_array(strtolower($extension), ['xlsx', 'xls'], true)
            && NewFormatPayrollParser::looksLikeNewFormat($fullPath)) {
            return new NewFormatPayrollParser();
        }

        return new XlsxParser();
    }

    public function store(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,txt|max:10240',
            'fiscal_year' => 'nullable|integer|min:2500|max:2700',
            'period_month' => 'nullable|integer|min:1|max:12',
        ], [
            'file.required' => 'กรุณาเลือกไฟล์',
            'file.mimes' => 'รองรับเฉพาะไฟล์ .xlsx, .xls, .txt',
            'file.max' => 'ขนาดไฟล์ต้องไม่เกิน 10 MB',
            'fiscal_year.integer' => 'ปีงบประมาณต้องเป็นตัวเลข',
            'period_month.min' => 'งวดเดือนต้องอยู่ระหว่าง 1-12',
            'period_month.max' => 'งวดเดือนต้องอยู่ระหว่าง 1-12',
        ]);

        // งวดของไฟล์ — ถ้าระบุเดือนมา จะอนุมานปีงบ/ปีปฏิทินให้เอง
        $period = null;
        if ($request->filled('period_month')) {
            $periodMonth = (int) $request->input('period_month');
            $fiscalYear = $request->filled('fiscal_year')
                ? (int) $request->input('fiscal_year')
                : ReserveFundCalculation::currentFiscalYear();

            $period = [
                'fiscal_year'  => $fiscalYear,
                'period_month' => $periodMonth,
                'period_year'  => ReserveFundCalculation::calendarYearOf($fiscalYear, $periodMonth),
            ];
        }

        $file = $request->file('file');
        $originalName = $file->getClientOriginalName();
        $extension = pathinfo($originalName, PATHINFO_EXTENSION);

        // เก็บไฟล์
        $path = $file->store('imports', 'local');
        $fullPath = Storage::disk('local')->path($path);

        // อ่านข้อมูลก่อน import เพื่อส่ง preview — ต้องใช้ parser ที่ตรงกับรูปแบบไฟล์
        $parser = $this->resolveParser($extension, $fullPath);
        $previewData = $parser->parse($fullPath);
        $preview = array_slice($previewData, 0, 10);   // เอา 10 แถวแรก

        // ประมวลผล (ใช้ parser ตัวเดิม ไม่ต้องอ่านไฟล์ซ้ำ)
        $import = $this->importService->process(
            $fullPath,
            $originalName,
            $request->user()->id,
            $parser,
            $period
        );

        // คอลัมน์รายรับที่หาไม่เจอในไฟล์ — ค่านั้นจะถูกบันทึกเป็น 0
        $missingIncomeFields = $parser->missingReserveIncomeFields();

        // แถวที่ผูกกับทะเบียนบุคลากรไม่ได้ — เลขบัตรประชาชนอาจพิมพ์ผิด
        $linkWarnings = collect($import->row_errors ?? [])
            ->where('type', 'link')
            ->values()
            ->all();

        $warning = null;
        if ($missingIncomeFields !== []) {
            $warning = 'ไม่พบคอลัมน์ ' . implode(', ', $missingIncomeFields)
                . ' ในไฟล์ — คอลัมน์เหล่านี้จะถูกบันทึกเป็น 0 และทำให้ฐานคำนวณเงินสำรองขาดไป';
        }

        return response()->json([
            'message' => 'นำเข้าข้อมูลสำเร็จ',
            'import' => $import,
            'preview' => $preview,
            'missing_income_fields' => $missingIncomeFields,
            'link_warnings' => $linkWarnings,
            'link_warning_count' => count($linkWarnings),
            'warning' => $warning,
            'uploader' => [
                'id' => $request->user()->id,
                'name' => $request->user()->name,
                'role' => $request->user()->role,
            ],
        ], 201);
    }

    /**
     * ดูประวัติการนำเข้า
     */
    public function index(Request $request)
    {
        $imports = Import::with('uploader:id,name')
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json($imports);
    }

    /**
     * ดูรายละเอียด import
     */
    public function show(Import $import)
    {
        $import->load('uploader:id,name');

        return response()->json([
            'import' => $import,
            'payrolls' => Payroll::where('import_id', $import->id)
                ->paginate(50),
        ]);
    }
}
