<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Import;
use App\Models\Payroll;
use App\Services\ImportService;
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
        $fullPath = Storage::disk('local')->path($path);

        // อ่านข้อมูลก่อน import เพื่อส่ง preview
        $parser = new XlsxParser();
        $previewData = $parser->parse($fullPath);
        $preview = array_slice($previewData, 0, 10);   // เอา 10 แถวแรก

        // ประมวลผล
        $import = $this->importService->process(
            $fullPath,
            $originalName,
            $request->user()->id
        );

        return response()->json([
            'message' => 'นำเข้าข้อมูลสำเร็จ',
            'import' => $import,
            'preview' => $preview,
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
