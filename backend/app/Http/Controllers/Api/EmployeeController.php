<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEmployeeRequest;
use App\Models\Department;
use App\Models\Duty;
use App\Models\Employee;
use App\Models\EmployeeStatus;
use App\Models\EmployeeType;
use App\Models\Group;
use App\Models\Position;
use App\Models\Prefix;
use App\Models\Work;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EmployeeController extends Controller
{
    /**
     * GET /api/hr/employees
     * List + search + filter + pagination
     */
    public function index(Request $request): JsonResponse
    {
        $query = Employee::with([
            'prefix:id,name',
            'employeeType:id,name',
            'position:id,name',
            'duty:id,name',
            'group:id,name',
            'work:id,name',
            'department:id,name',
            'status:id,name,color',
        ]);

        // Search
        $query->search($request->input('search'));

        // Filter
        $query->filter($request->only([
            'employee_type_id',
            'position_id',
            'duty_id',
            'group_id',
            'work_id',
            'status_id',
        ]));

        // Pagination
        $perPage = (int) $request->input('per_page', 10);
        $perPage = in_array($perPage, [10, 25, 50, 100]) ? $perPage : 10;

        $employees = $query->orderBy('id')->paginate($perPage);

        return response()->json([
            'data' => $employees->items(),
            'meta' => [
                'current_page' => $employees->currentPage(),
                'last_page'    => $employees->lastPage(),
                'per_page'     => $employees->perPage(),
                'total'        => $employees->total(),
                'from'         => $employees->firstItem(),
                'to'           => $employees->lastItem(),
            ],
        ]);
    }

    /**
     * POST /api/hr/employees
     * เพิ่มบุคลากรใหม่
     */
    public function store(StoreEmployeeRequest $request): JsonResponse
    {
        $employee = DB::transaction(function () use ($request) {
            return Employee::create([
                ...$request->validated(),
                'created_by' => $request->user()?->id,
                'updated_by' => $request->user()?->id,
            ]);
        });

        return response()->json([
            'message' => 'เพิ่มบุคลากรสำเร็จ',
            'data'    => $employee->load([
                'prefix:id,name',
                'employeeType:id,name',
                'position:id,name',
                'duty:id,name',
                'group:id,name',
                'work:id,name',
                'department:id,name',
                'status:id,name,color',
            ]),
        ], 201);
    }

    /**
     * GET /api/hr/employees/stats
     * ตัวเลขสำหรับ Stat Cards 5 ใบ
     */
    public function stats(): JsonResponse
    {
        $total = Employee::count();

        // นับตามประเภทบุคลากร
        $byType = DB::table('employees')
            ->join('employee_types', 'employees.employee_type_id', '=', 'employee_types.id')
            ->select('employee_types.name', DB::raw('COUNT(*) as count'))
            ->groupBy('employee_types.id', 'employee_types.name')
            ->pluck('count', 'name')
            ->toArray();

        return response()->json([
            'total'          => $total,
            'ข้าราชการ'        => $byType['ข้าราชการ'] ?? 0,
            'พนักงานราชการ'   => $byType['พนักงานราชการ'] ?? 0,
            'พนักงานกระทรวง'  => $byType['พนักงานกระทรวง'] ?? 0,
            'ลูกจ้าง'         => $byType['ลูกจ้าง'] ?? 0,
            'by_type'        => $byType,
        ]);
    }

    /**
     * GET /api/hr/lookups
     * รวมทุก dropdown ในคำขอเดียว
     */
    public function lookups(): JsonResponse
    {
        return response()->json([
            'prefixes'          => Prefix::orderBy('sort_order')->get(['id', 'name', 'short_name']),
            'employee_types'    => EmployeeType::orderBy('sort_order')->get(['id', 'name']),
            'positions'         => Position::orderBy('name')->get(['id', 'name']),
            'duties'            => Duty::orderBy('name')->get(['id', 'name']),
            'groups'            => Group::orderBy('name')->get(['id', 'name']),
            'works'             => Work::orderBy('name')->get(['id', 'name']),
            'departments'       => Department::orderBy('name')->get(['id', 'name']),
            'employee_statuses' => EmployeeStatus::orderBy('sort_order')->get(['id', 'name', 'color']),
        ]);
    }
}
