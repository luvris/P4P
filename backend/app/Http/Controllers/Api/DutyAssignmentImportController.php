<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DutyAssignmentImportRequest;
use App\Models\Import;
use App\Services\DutyAssignmentImportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Controller สำหรับ "นำเข้าข้อมูลการอยู่ภารกิจของบุคลากร"
 *
 * แยกจาก HrImportController ทั้งหมด — ไม่แตะ flow import บุคลากรเดิม
 */
class DutyAssignmentImportController extends Controller
{
    public function __construct(
        protected DutyAssignmentImportService $service
    ) {}

    /**
     * POST /api/hr/duty-assignment-imports/preview
     */
    public function preview(DutyAssignmentImportRequest $request)
    {
        $file = $request->file('file');
        $originalName = $file->getClientOriginalName();

        $path = $file->store('imports', 'local');
        $fullPath = Storage::disk('local')->path($path);

        $missing = $this->service->missingColumns($fullPath);

        if (in_array('PID', $missing, true) || in_array('DUTY', $missing, true)) {
            return response()->json([
                'message'         => 'รูปแบบไฟล์ไม่ถูกต้อง — ต้องมีคอลัมน์ PID และ DUTY',
                'missing_columns' => $missing,
            ], 422);
        }

        $rows = $this->service->parse($fullPath);
        $result = $this->service->preview($rows);

        return response()->json([
            'message'         => 'อ่านข้อมูลไฟล์สำเร็จ',
            'file_name'       => $originalName,
            'total_rows'      => count($rows),
            'missing_columns' => $missing,
            'preview'         => $result['preview'],
            'warnings'        => $result['warnings'],
            'warning_count'   => $result['warning_count'],
        ]);
    }

    /**
     * POST /api/hr/duty-assignment-imports
     */
    public function store(DutyAssignmentImportRequest $request)
    {
        $file = $request->file('file');
        $originalName = $file->getClientOriginalName();

        $path = $file->store('imports', 'local');
        $fullPath = Storage::disk('local')->path($path);

        $missing = $this->service->missingColumns($fullPath);

        if (in_array('PID', $missing, true) || in_array('DUTY', $missing, true)) {
            return response()->json([
                'message'         => 'รูปแบบไฟล์ไม่ถูกต้อง — ต้องมีคอลัมน์ PID และ DUTY',
                'missing_columns' => $missing,
            ], 422);
        }

        $rows = $this->service->parse($fullPath);

        if ($rows === []) {
            return response()->json([
                'message' => 'ไม่พบข้อมูลในไฟล์',
                'summary' => [
                    'total'     => 0,
                    'updated'   => 0,
                    'unchanged' => 0,
                    'skipped'   => 0,
                    'errors'    => 0,
                ],
            ], 422);
        }

        $result = $this->service->import(
            $rows,
            $originalName,
            $path,
            $request->user()->id
        );

        return response()->json([
            'message'       => 'นำเข้าข้อมูลการอยู่ภารกิจสำเร็จ',
            'import'        => $result['import'],
            'summary'       => $result['summary'],
            'row_errors'    => $result['row_errors'],
            'warning_count' => $result['warning_count'],
        ], 201);
    }

    /**
     * GET /api/hr/duty-assignment-imports
     */
    public function index(Request $request)
    {
        $imports = Import::with('uploader:id,name')
            ->where('import_type', DutyAssignmentImportService::IMPORT_TYPE)
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json($imports);
    }
}
