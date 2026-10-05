<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Import;
use App\Models\Payroll;
use App\Services\PayrollFileImportService;
use App\Services\Parsers\NewFormatPayrollParser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * นำเข้าไฟล์เงินเดือน — ใช้ได้ทั้ง HR และการเงิน
 *
 * ไฟล์เดียวให้ข้อมูลสองฝั่ง: ทะเบียนบุคลากร (สำหรับจัดทำบุคลากร/ใบเบิกค่าใช้จ่าย)
 * และแถวเงินเดือนรายงวด (สำหรับคำนวณเงินสำรอง) จึงไม่ต้องแยกหน้านำเข้า
 * ตามแผนก — ลิงก์/ตำแหน่งเดียว ใช้ได้ทุก role
 */
class ImportController extends Controller
{
    public function __construct(
        protected PayrollFileImportService $payrollFileImport
    ) {}

    /**
     * อ่านไฟล์เพื่อดูตัวอย่างก่อนยืนยัน — ยังไม่เขียนอะไรลงฐาน
     */
    public function preview(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls|max:10240',
        ], [
            'file.required' => 'กรุณาเลือกไฟล์',
            'file.mimes'    => 'รองรับเฉพาะไฟล์ .xlsx หรือ .xls',
            'file.max'      => 'ขนาดไฟล์ต้องไม่เกิน 10 MB',
        ]);

        $file = $request->file('file');
        $path = $file->store('imports', 'local');
        $fullPath = Storage::disk('local')->path($path);

        if (! NewFormatPayrollParser::looksLikeNewFormat($fullPath)) {
            return response()->json([
                'message' => 'ไฟล์นี้ไม่ใช่ไฟล์เงินเดือนรูปแบบใหม่'
                    . ' — ต้องมีคอลัมน์ ลำดับที่ / ปี / เดือน ครบ',
            ], 422);
        }

        $analysis = $this->payrollFileImport->analyze($fullPath);

        if ($analysis['rows'] === []) {
            return response()->json([
                'message'       => 'ไม่พบข้อมูลในไฟล์',
                'warnings'      => $analysis['warnings'],
                'warning_count' => $analysis['warning_count'],
            ], 422);
        }

        return response()->json([
            'message'       => 'อ่านข้อมูลไฟล์สำเร็จ',
            'file_name'     => $file->getClientOriginalName(),
            'payroll'       => [
                'preview'    => $analysis['payroll_preview'],
                'total_rows' => $analysis['payroll_total_rows'],
            ],
            'employee'      => $analysis['employee'],
            'warnings'      => $analysis['warnings'],
            'warning_count' => $analysis['warning_count'],
        ]);
    }

    /**
     * ยืนยันนำเข้า — เขียนทั้งทะเบียนบุคลากรและแถวเงินเดือน
     */
    public function store(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls|max:10240',
            'fiscal_year' => 'nullable|integer|min:2500|max:2700',
            'period_month' => 'nullable|integer|min:1|max:12',
        ], [
            'file.required' => 'กรุณาเลือกไฟล์',
            'file.mimes' => 'รองรับเฉพาะไฟล์ .xlsx หรือ .xls',
            'file.max' => 'ขนาดไฟล์ต้องไม่เกิน 10 MB',
            'fiscal_year.integer' => 'ปีงบประมาณต้องเป็นตัวเลข',
            'period_month.min' => 'งวดเดือนต้องอยู่ระหว่าง 1-12',
            'period_month.max' => 'งวดเดือนต้องอยู่ระหว่าง 1-12',
        ]);

        $file = $request->file('file');
        $originalName = $file->getClientOriginalName();

        $path = $file->store('imports', 'local');
        $fullPath = Storage::disk('local')->path($path);

        if (! NewFormatPayrollParser::looksLikeNewFormat($fullPath)) {
            return response()->json([
                'message' => 'ไฟล์นี้ไม่ใช่ไฟล์เงินเดือนรูปแบบใหม่'
                    . ' — ต้องมีคอลัมน์ ลำดับที่ / ปี / เดือน ครบ',
            ], 422);
        }

        $period = $this->payrollFileImport->periodFromRequest(
            $request->filled('period_month') ? (int) $request->input('period_month') : null,
            $request->filled('fiscal_year') ? (int) $request->input('fiscal_year') : null,
        );

        $result = $this->payrollFileImport->import(
            $fullPath,
            $path,
            $originalName,
            $request->user()->id,
            $period
        );

        $payrollImport = $result['payroll'];

        // คอลัมน์รายรับที่หาไม่เจอในไฟล์ — ค่านั้นจะถูกบันทึกเป็น 0
        $missingIncomeFields = $result['missing_income_fields'];

        // แถวที่นำเข้าแล้วแต่ไม่เข้าฐานเงินสำรอง (เลขบัตรว่าง/ไม่ตรงทะเบียน)
        $unlinked = collect($payrollImport->row_errors ?? [])
            ->firstWhere('type', 'unlinked_summary');

        $unlinkedSummary = $unlinked ? [
            'rows'         => (int) $unlinked['unlinked_rows'],
            'total_income' => (float) $unlinked['unlinked_total_income'],
            'by_reason'    => $unlinked['by_reason'] ?? [],
            'samples'      => $unlinked['samples'] ?? [],
            'message'      => sprintf(
                'นำเข้าสำเร็จ %d คน-งวด แต่มี %d คน-งวด รวม %s บาท ที่ยังไม่ถูกนับในฐานเงินสำรอง เพราะยังไม่มีเลขบัตรประชาชนหรือผูกกับทะเบียนบุคลากรไม่ได้',
                (int) $payrollImport->success_rows,
                (int) $unlinked['unlinked_rows'],
                number_format((float) $unlinked['unlinked_total_income'], 2)
            ),
        ] : null;

        $warning = null;
        if ($missingIncomeFields !== []) {
            $warning = 'ไม่พบคอลัมน์ ' . implode(', ', $missingIncomeFields)
                . ' ในไฟล์ — คอลัมน์เหล่านี้จะถูกบันทึกเป็น 0 และทำให้ฐานคำนวณเงินสำรองขาดไป';
        }

        if ($result['employee_error'] !== null) {
            $warning = trim(($warning ? $warning . ' ' : '')
                . 'บันทึกทะเบียนบุคลากรไม่สำเร็จ: ' . $result['employee_error']);
        }

        return response()->json([
            'message' => 'นำเข้าข้อมูลสำเร็จ',
            'import'  => $payrollImport,
            'preview' => $result['preview'],
            'total_rows' => $result['total_rows'],
            'payroll' => [
                'success_rows' => (int) $payrollImport->success_rows,
                'error_rows'   => (int) $payrollImport->error_rows,
            ],
            'employee' => $result['employee'] ? [
                'import_id' => $result['employee']->id,
                'inserted'  => (int) $result['employee']->inserted_rows,
                'updated'   => (int) $result['employee']->updated_rows,
                'total'     => (int) $result['employee']->success_rows,
            ] : null,
            'employee_error' => $result['employee_error'],
            'missing_income_fields' => $missingIncomeFields,
            'unlinked_summary' => $unlinkedSummary,
            'warning' => $warning,
            'uploader' => [
                'id' => $request->user()->id,
                'name' => $request->user()->name,
                'role' => $request->user()->role,
            ],
        ], 201);
    }

    /**
     * ประวัติการนำเข้าไฟล์เงินเดือน
     */
    public function index(Request $request)
    {
        $imports = Import::with('uploader:id,name')
            ->where('import_type', '!=', 'hr')
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json($imports);
    }

    /**
     * รายละเอียดของการนำเข้าหนึ่งรายการ
     */
    public function show(Import $import)
    {
        $import->load('uploader:id,name');

        return response()->json([
            'import'   => $import,
            'payrolls' => Payroll::where('import_id', $import->id)->paginate(50),
        ]);
    }
}