<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Import;
use App\Models\Payroll;
use App\Services\ImportService;
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
    public function store(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,txt|max:10240',
        ], [
            'file.required' => 'กรุณาเลือกไฟล์',
            'file.mimes' => 'รองรับเฉพาะไฟล์ .xlsx, .xls, .txt',
            'file.max' => 'ขนาดไฟล์ต้องไม่เกิน 10 MB',
        ]);

        $file = $request->file('file');
        $originalName = $file->getClientOriginalName();

        // เก็บไฟล์
        $path = $file->store('imports', 'local');

        //ใช้ Storage::disk() เพื่อให้ได้ path ที่ถูกต้อง
        $fullPath = Storage::disk('local')->path($path);

        // ตรวจสอบว่าไฟล์มีจริง
        if (!file_exists($fullPath)) {
            return response()->json([
                'message' => 'ไม่พบไฟล์หลังอัปโหลด',
                'path' => $fullPath,
            ], 500);
        }

        // ประมวลผล
        $import = $this->importService->process(
            $fullPath,
            $originalName,
            $request->user()->id
        );

        return response()->json([
            'message' => 'นำเข้าข้อมูลสำเร็จ',
            'import' => $import,
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
