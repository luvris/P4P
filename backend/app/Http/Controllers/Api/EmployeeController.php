<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEmployeeRequest;
use App\Http\Requests\UpdateEmployeeRequest;
use App\Models\Duty;
use App\Models\Employee;
use App\Models\EmployeeStatus;
use App\Models\EmployeeType;
use App\Models\Position;
use App\Models\Prefix;
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
                'status:id,name,color',
            ]),
        ], 201);
    }

    /**
     * PUT /api/hr/employees/{employee}
     * แก้ไขข้อมูลบุคลากร
     */
    public function update(UpdateEmployeeRequest $request, Employee $employee): JsonResponse
    {
        $employee = DB::transaction(function () use ($request, $employee) {
            $employee->update([
                ...$request->validated(),
                'updated_by' => $request->user()?->id,
            ]);
            return $employee;
        });

        return response()->json([
            'message' => 'บันทึกข้อมูลสำเร็จ',
            'data'    => $employee->load([
                'prefix:id,name',
                'employeeType:id,name',
                'position:id,name',
                'duty:id,name',
                'group:id,name',
                'work:id,name',
                'status:id,name,color',
            ]),
        ]);
    }

    /**
     * GET /api/hr/employees/stats
     */
    public function stats(): JsonResponse
    {
        $total = Employee::count();

        $byType = DB::table('employees')
            ->join('employee_types', 'employees.employee_type_id', '=', 'employee_types.id')
            ->select('employee_types.name', DB::raw('COUNT(*) as count'))
            ->groupBy('employee_types.id', 'employee_types.name')
            ->pluck('count', 'name')
            ->toArray();

        // สร้าง response แบบ dynamic จากข้อมูลจริง
        $response = [
            'total' => $total,
            'by_type' => $byType,
        ];
        
        // เพิ่มแต่ละประเภทเป็น key แยก (backward compatible)
        foreach ($byType as $name => $count) {
            $response[$name] = $count;
        }

        return response()->json($response);
    }

    /**
     * GET /api/hr/lookups
     * รวมทุก dropdown + hierarchy (duty → group → work)
     */
    public function lookups(): JsonResponse
    {
        // ============================================
        // 1. โหลด duties พร้อม nested groups + works
        // ============================================
        $duties = Duty::with([
            'groups' => function ($q) {
                $q->orderBy('name');
            },
            'groups.works' => function ($q) {
                $q->orderBy('name');
            },
        ])
            ->orderBy('name')
            ->get()
            ->map(function ($duty) {
                return [
                    'id'   => $duty->id,
                    'name' => $duty->name,
                    'groups' => $duty->groups->map(function ($group) {
                        return [
                            'id'      => $group->id,
                            'name'    => $group->name,
                            'duty_id' => $group->duty_id,
                            'works'   => $group->works->map(function ($work) {
                                return [
                                    'id'       => $work->id,
                                    'name'     => $work->name,
                                    'group_id' => $work->group_id,
                                ];
                            })->values(),
                        ];
                    })->values(),
                ];
            });

        // ============================================
        // 2. Return lookups
        // ============================================
        return response()->json([
            'prefixes'          => Prefix::orderBy('sort_order')->get(['id', 'name', 'short_name']),
            'employee_types'    => EmployeeType::orderBy('sort_order')->get(['id', 'name']),
            'positions'         => Position::orderBy('name')->get(['id', 'name']),
            'employee_statuses' => EmployeeStatus::orderBy('sort_order')->get(['id', 'name', 'color']),
            'duties'            => $duties,
        ]);
    }
}
