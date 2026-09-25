<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\HrImportRequest;
use App\Services\HrImportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class HrImportController extends Controller
{
    public function __construct(
        protected HrImportService $hrImportService
    ) {}

    /**
     * เลือกไฟล์แล้วดู preview (10 แถวแรก) พร้อมคำเตือนก่อนยืนยัน import
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

        $rows = $this->hrImportService->parse($fullPath, $originalName);

        $result = $this->hrImportService->preview($rows);

        return response()->json([
            'message'       => 'อ่านข้อมูลไฟล์สำเร็จ',
            'file_name'     => $originalName,
            'total_rows'    => count($rows),
            'preview'       => $result['preview'],
            'warnings'      => $result['warnings'],
            'warning_count' => $result['warning_count'],
        ]);
    }

    /**
     * ยืนยันการนำเข้าข้อมูลบุคลากร (upsert ผ่าน citizen_id)
     */
    public function store(HrImportRequest $request)
    {
        $file = $request->file('file');
        $originalName = $file->getClientOriginalName();

        $path = $file->store('imports', 'local');
        $fullPath = Storage::disk('local')->path($path);

        $rows = $this->hrImportService->parse($fullPath, $originalName);

        if (empty($rows)) {
            return response()->json([
                'message' => 'ไม่พบข้อมูลในไฟล์',
                'summary' => [
                    'inserted' => 0,
                    'updated'  => 0,
                    'skipped'  => 0,
                    'errors'   => 0,
                    'total'    => 0,
                ],
            ], 422);
        }

        $result = $this->hrImportService->import(
            $rows,
            $originalName,
            $path,
            $request->user()->id
        );

        return response()->json([
            'message'       => 'นำเข้าข้อมูลบุคลากรสำเร็จ',
            'import'        => $result['import'],
            'summary'       => $result['summary'],
            'warnings'      => $result['warnings'],
            'warning_count' => $result['warning_count'],
            'row_errors'    => $result['row_errors'],
        ], 201);
    }

    /**
     * ประวัติการนำเข้าข้อมูลบุคลากร (เฉพาะ type = hr)
     */
    public function index(Request $request)
    {
        $imports = \App\Models\Import::with('uploader:id,name')
            ->where('import_type', 'hr')
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json($imports);
    }
}