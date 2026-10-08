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
     * สถานะบุคลากรที่เลือกใน autocomplete ได้ — เอาเฉพาะคนที่ปฏิบัติงานอยู่
     * (คนลาออก/ลาศึกษาต่อ/ลาเลี้ยงลูก ไม่ต้องขึ้นมาให้เลือก)
     */
    private const ACTIVE_EMPLOYEE_STATUSES = ['ปฏิบัติงานอยู่'];

    /** จำนวนหลักขั้นต่ำของเลขบัตรประชาชนก่อนเริ่มแนะนำ "ใกล้เคียง" (กันเดามั่วตอนพิมพ์สั้น ๆ) */
    private const NEAR_MATCH_MIN_DIGITS = 6;

    /** ระยะห่างสูงสุด (แก้/เพิ่ม/ลบได้กี่ตัวอักษร) ที่ยังถือว่า "ใกล้เคียง" */
    private const NEAR_MATCH_MAX_DISTANCE = 2;

    /** เพดานจำนวนแถวที่สแกนหาเลขใกล้เคียง — กันฐานข้อมูลโตแล้วช้า */
    private const NEAR_MATCH_SCAN_LIMIT = 5000;

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
            'latestPayroll:id,citizen_id,bank_account', // เพิ่ม relationship เลขที่บัญชี
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
     * GET /api/hr/employees/suggest
     *
     * ค้นหาบุคลากรแบบ "พิมพ์แล้วเด้ง" สำหรับ autocomplete
     * - ค้นจากเลขบัตรประชาชน (พิมพ์กี่หลักก็เจอ), PID, ชื่อ, นามสกุล, เลขที่ตำแหน่ง, ชื่อตำแหน่ง
     * - ถ้าพิมพ์เป็นตัวเลขตั้งแต่ 6 หลักขึ้นไปแล้ว "ไม่เจอตรง ๆ" จะแนบเลขบัตรประชาชนที่ใกล้เคียง
     *   (ระยะ Levenshtein ≤ 2) มาให้ด้วย โดยติดธง near = true เพื่อให้ UI เตือนให้ตรวจสอบก่อนเลือก
     */
    public function suggest(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q'     => ['nullable', 'string', 'max:255'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $term   = trim((string) ($validated['q'] ?? ''));
        $limit  = (int) ($validated['limit'] ?? 20);
        $digits = preg_replace('/\D+/', '', $term) ?? '';

        // เอาเฉพาะคนที่ยังปฏิบัติงานอยู่ — ทั้งผลการค้นหาปกติและการหาเลขใกล้เคียง
        $base = fn () => Employee::query()
            ->with(['prefix:id,name', 'position:id,name'])
            ->whereHas('status', fn ($q) => $q->whereIn('name', self::ACTIVE_EMPLOYEE_STATUSES));

        // ไม่พิมพ์อะไร → คืนรายชื่อชุดแรกให้เลือกได้เลย
        if ($term === '') {
            $employees = $base()->orderBy('first_name')->limit($limit)->get();

            return response()->json([
                'data' => $employees->map(fn ($e) => $this->formatSuggestion($e))->values(),
                'meta' => ['near' => false],
            ]);
        }

        $matches = $base()
            ->where(function ($q) use ($term) {
                $q->where('citizen_id', 'like', "%{$term}%")
                    ->orWhere('employee_id', 'like', "%{$term}%")
                    ->orWhere('first_name', 'like', "%{$term}%")
                    ->orWhere('last_name', 'like', "%{$term}%")
                    ->orWhere('position_number', 'like', "%{$term}%")
                    ->orWhereHas('position', fn ($pq) => $pq->where('name', 'like', "%{$term}%"));
            })
            ->orderByRaw('citizen_id = ? desc', [$term]) // เลขตรงเป๊ะขึ้นก่อน
            ->orderBy('first_name')
            ->limit($limit)
            ->get();

        $data = $matches->map(fn ($e) => $this->formatSuggestion($e))->values()->all();
        $near = false;

        // ยังได้ไม่ครบ + พิมพ์เป็นตัวเลขยาวพอ → หาเลขบัตรประชาชนที่ "ใกล้เคียง" มาเติม
        if (count($data) < $limit && strlen($digits) >= self::NEAR_MATCH_MIN_DIGITS) {
            $skip = $matches->pluck('id')->all();

            $ranked = $base()
                ->whereNotNull('citizen_id')
                ->when($skip !== [], fn ($q) => $q->whereNotIn('id', $skip))
                ->limit(self::NEAR_MATCH_SCAN_LIMIT)
                ->get()
                ->map(fn ($e) => [
                    'employee' => $e,
                    'distance' => $this->citizenIdDistance($digits, $e->citizen_id),
                ])
                ->filter(fn ($row) => $row['distance'] !== null && $row['distance'] <= self::NEAR_MATCH_MAX_DISTANCE)
                ->sortBy('distance')
                ->values()
                ->take($limit - count($data));

            foreach ($ranked as $row) {
                $data[] = $this->formatSuggestion($row['employee'], true, $row['distance']);
            }

            $near = $ranked->isNotEmpty();
        }

        return response()->json([
            'data' => $data,
            'meta' => ['near' => $near],
        ]);
    }

    /**
     * จัดรูปบุคลากร 1 คนให้ UI อ่านง่าย
     */
    private function formatSuggestion(Employee $employee, bool $near = false, ?int $distance = null): array
    {
        return [
            'id'            => $employee->id,
            'pid'           => $employee->employee_id,
            'citizen_id'    => $employee->citizen_id,
            'full_name'     => $employee->full_name,
            'first_name'    => $employee->first_name,
            'last_name'     => $employee->last_name,
            'position_name' => $employee->position?->name,
            // salary = ฐานที่ปรับแล้ว, latest_salary = ค่าจากไฟล์เงินเดือนล่าสุด
            // ฝั่ง UI ใช้ salary ?? latest_salary เป็น "เงินเดือนปัจจุบัน"
            'salary'        => $employee->salary,
            'latest_salary' => $employee->latest_salary,
            'near'          => $near,
            'distance'      => $distance,
        ];
    }

    /**
     * ระยะห่างระหว่างเลขที่พิมพ์กับเลขบัตรประชาชนจริง (เทียบแบบไม่สนใจศูนย์นำหน้า)
     * คืน null ถ้าฝั่งใดไม่มีตัวเลขเลย
     */
    private function citizenIdDistance(string $typed, ?string $citizenId): ?int
    {
        $target = preg_replace('/\D+/', '', (string) $citizenId) ?? '';

        if ($typed === '' || $target === '') {
            return null;
        }

        return min(
            levenshtein($typed, $target),
            levenshtein(ltrim($typed, '0') ?: '0', ltrim($target, '0') ?: '0'), // ผู้ใช้มักไม่พิมพ์ศูนย์นำหน้า
        );
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
