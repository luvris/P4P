<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\HrImportRequest;
use App\Models\Import;
use App\Services\NewFormatEmployeeImportService;
use App\Services\Parsers\NewFormatPayrollParser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * นำเข้าทะเบียนบุคลากรจากไฟล์เงินเดือนรูปแบบใหม่ (39 คอลัมน์)
 *
 * คอลัมน์ที่ใช้สร้างทะเบียน:
 *   คำนำหน้า | ชื่อ | นามสกุล | ประเภท | ตำแหน่ง | ตำแหน่งเลขที่
 *   ID CARD | เลขที่บัญชี | เงินเดือน | ปี | เดือน
 *
 * ไฟล์รวมหลายงวด คนเดิมจึงมีหลายแถว — service จะยุบเหลืองวดล่าสุดต่อคน
 * จึงอัปเดตทะเบียนได้ถูกต้อง ไม่ถูกงวดเก่าทับ
 */
class HrImportController extends Controller
{
    public function __construct(
        protected NewFormatEmployeeImportService $service
    ) {}

    /**
     * เลือกไฟล์แล้วดู preview (10 คนแรก) พร้อมคำเตือนก่อนยืนยัน import
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
        $originalName = $file->getClientOriginalName();

        $path = $file->store('imports', 'local');
        $fullPath = Storage::disk('local')->path($path);

        // ต้องเป็นไฟล์รูปแบบใหม่ ไม่งั้นแจ้งชัดว่าเป็นรูปแบบไหน
        if (! NewFormatPayrollParser::looksLikeNewFormat($fullPath)) {
            return response()->json([
                'message' => 'ไฟล์นี้ไม่ใช่ไฟล์เงินเดือนรูปแบบใหม่'
                    . ' — ต้องมีคอลัมน์ ลำดับที่ / ปี / เดือน ครบ',
            ], 422);
        }

        $parsed = $this->service->parse($fullPath);

        if ($parsed['data'] === []) {
            return response()->json([
                'message'       => 'ไม่พบข้อมูลบุคลากรในไฟล์ (ไม่พบเลขบัตรประชาชน)',
                'warnings'      => $parsed['warnings'],
                'warning_count' => count($parsed['warnings']),
            ], 422);
        }

        $result = $this->service->preview($parsed['data'], $parsed['warnings']);

        return response()->json([
            'message'       => 'อ่านข้อมูลไฟล์สำเร็จ',
            'file_name'     => $originalName,
            'total_rows'    => $result['preview']['total_rows'],
            'preview'       => $result['preview'],
            'warnings'      => $result['warnings'],
            'warning_count' => $result['warning_count'],
        ]);
    }

    /**
     * ยืนยันการนำเข้าทะเบียนบุคลากร (upsert ผ่านเลขบัตรประชาชน)
     */
    public function store(HrImportRequest $request)
    {
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

        $parsed = $this->service->parse($fullPath);

        if ($parsed['data'] === []) {
            return response()->json([
                'message' => 'ไม่พบข้อมูลบุคลากรในไฟล์ (ไม่พบเลขบัตรประชาชน)',
            ], 422);
        }

        $import = $this->service->import(
            $parsed['data'],
            $originalName,
            $path,
            $request->user()->id
        );

        return response()->json([
            'message' => 'นำเข้าข้อมูลบุคลากรสำเร็จ',
            'import'  => $import,
            'summary' => [
                'inserted' => (int) $import->inserted_rows,
                'updated'  => (int) $import->updated_rows,
                'skipped'  => 0,
                'errors'   => (int) $import->error_rows,
                'total'    => (int) $import->success_rows,
            ],
            'warnings'      => $parsed['warnings'],
            'warning_count' => count($parsed['warnings']),
        ], 201);
    }

    /**
     * ประวัติการนำเข้าข้อมูลบุคลากร (เฉพาะ type = hr)
     */
    public function index(Request $request)
    {
        $imports = Import::with('uploader:id,name')
            ->where('import_type', 'hr')
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json($imports);
    }
}