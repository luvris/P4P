<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\SalaryAdjustment;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SalaryAdjustmentController extends Controller
{
    /** ความจุตาม schema จริง — money: decimal(12,2), percent: decimal(6,2) */
    private const MONEY_MAX = 9999999999.99;

    private const PERCENT_MAX = 9999.99;

    /** ทศนิยมของเงินเดือน (decimal(12,2)) ใช้ตอน normalize ค่าก่อนเทียบ/บันทึก */
    private const MONEY_SCALE = 2;

    // GET /api/hr/salary-adjustments
    // รายการ log การปรับฐานเงินเดือน (pagination + search + filter)
    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->input('per_page', 10);
        $perPage = in_array($perPage, [10, 25, 50, 100], true) ? $perPage : 10;

        $search = $request->input('search');

        $query = SalaryAdjustment::with([
            'employee' => fn ($q) => $q->with('prefix', 'position', 'duty'),
            'creator',
        ])
            ->when($search, function ($q) use ($search) {
                $q->whereHas('employee', function ($eq) use ($search) {
                    $eq->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('citizen_id', 'like', "%{$search}%");
                });
            })
            ->when($request->input('adjustment_type'), fn ($q, $v) => $q->where('adjustment_type', $v))
            ->when($request->input('date_from'), fn ($q, $v) => $q->whereDate('adjustment_date', '>=', $v))
            ->when($request->input('date_to'), fn ($q, $v) => $q->whereDate('adjustment_date', '<=', $v))
            ->orderByDesc('adjustment_date')
            ->orderByDesc('id');

        $paginated = $query->paginate($perPage);

        return response()->json($paginated);
    }

    // GET /api/hr/salary-adjustments/summary
    // ภาพรวม: จำนวนครั้งที่ปรับ, ยอดปรับเพิ่มรวม
    public function summary(Request $request): JsonResponse
    {
        $query = SalaryAdjustment::query()
            ->when($request->input('adjustment_type'), fn ($q, $v) => $q->where('adjustment_type', $v))
            ->when($request->input('date_from'), fn ($q, $v) => $q->whereDate('adjustment_date', '>=', $v))
            ->when($request->input('date_to'), fn ($q, $v) => $q->whereDate('adjustment_date', '<=', $v));

        $total = (clone $query)->count();
        $totalIncrease = (float) (clone $query)->sum('increase_amount');
        $totalEmployees = (clone $query)->distinct('employee_id')->count('employee_id');

        return response()->json([
            'data' => [
                'total_adjustments' => $total,
                'total_increase' => round($totalIncrease, 2),
                'total_employees' => $totalEmployees,
            ],
        ]);
    }

    // POST /api/hr/salary-adjustments
    // บันทึกการปรับฐานเงินเดือน + อัปเดต salary บนตาราง employees
    //
    // Contract:
    // - ฐานเงินเดือนปัจจุบัน = employees.salary ?? payrolls.total_income ของงวดล่าสุด
    //   (ค่า 0 ถือเป็นค่าจริง) — ใช้ยอดรวมรายรับจากไฟล์ตามที่ฝ่ายการเงินกำหนด
    //   ไม่ใช้คอลัมน์ "เงินเดือน" (latest_salary) เป็นฐานอีกต่อไป
    // - expected_old_salary ใช้ตรวจว่าฐานที่ HR เห็นยังตรงกับ DB เท่านั้น (ไม่ได้ใช้สร้างประวัติ)
    // - สร้างประวัติ + อัปเดต salary ต้องสำเร็จพร้อมกัน มิฉะนั้น rollback ทั้งคู่
    // - ไม่แตะ latest_salary / payrolls
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'new_salary' => ['required', 'numeric', 'min:0', 'max:' . self::MONEY_MAX],
            'expected_old_salary' => ['required', 'numeric', 'min:0', 'max:' . self::MONEY_MAX],
            'adjustment_date' => 'required|date',
            'adjustment_type' => 'nullable|string|max:50',
            'note' => 'nullable|string|max:1000',
        ], [
            'new_salary.max' => 'เงินเดือนใหม่เกินขอบเขตที่ระบบรองรับ',
            'expected_old_salary.max' => 'เงินเดือนก่อนปรับเกินขอบเขตที่ระบบรองรับ',
        ]);

        $userId = $request->user()?->id;

        $result = DB::transaction(function () use ($validated, $userId) {
            // ล็อกแถวก่อนอ่านฐาน — กันสองคำขอเห็นฐานเดียวกันพร้อมกัน
            $employee = Employee::whereKey($validated['employee_id'])->lockForUpdate()->first();

            if (! $employee) {
                // ผ่าน validation แต่ไม่มีแถวจริง (เช่น ถูกระหว่าง validate) → ตอบแบบ not-found ของระบบ ไม่บันทึกอะไร
                throw (new ModelNotFoundException)->setModel(Employee::class, $validated['employee_id']);
            }

            // cast decimal:2 ได้ string|null — ห้ามใช้ truthy check เพราะค่า 0 เป็นค่าจริง
            $salary = $employee->salary;
            $payrollIncome = $employee->latest_payroll_income;

            $baseValue = $salary ?? $payrollIncome;
            $baseSource = $salary !== null ? 'salary' : 'payroll_total_income';

            if ($baseValue === null) {
                return ['error' => response()->json(['message' => 'ไม่มีข้อมูลฐานเงินเดือน'], 422)];
            }

            $base = round((float) $baseValue, self::MONEY_SCALE);

            // ตรวจว่าฐานที่ HR เห็นยังตรงกับ DB — normalize ตาม precision เงินเดือนก่อนเทียบ (15000 == 15000.00)
            $expected = number_format((float) $validated['expected_old_salary'], self::MONEY_SCALE, '.', '');
            $actual = number_format($base, self::MONEY_SCALE, '.', '');

            if ($expected !== $actual) {
                return ['error' => response()->json([
                    'message' => 'เงินเดือนของพนักงานถูกเปลี่ยนระหว่างทำรายการ กรุณาตรวจสอบยอดใหม่และยืนยันอีกครั้ง',
                    'code' => 'SALARY_BASE_CHANGED',
                    'data' => [
                        'base' => $base,
                        'base_source' => $baseSource,
                        'salary' => $salary !== null ? (float) $salary : null,
                        'latest_payroll_income' => $payrollIncome,
                    ],
                ], 409)];
            }

            $newSalary = round((float) $validated['new_salary'], self::MONEY_SCALE);
            $increase = round($newSalary - $base, self::MONEY_SCALE);
            // ฐาน 0 → เก็บ percent เป็น null ห้ามหารด้วยศูนย์
            $percent = $base > 0
                ? round($increase / $base * 100, 2)
                : null;

            // ตรวจความจุคอลัมน์ตาม schema ก่อนเขียนข้อมูล — ห้ามให้ truncate/บันทึกผิด
            if ($newSalary > self::MONEY_MAX || abs($increase) > self::MONEY_MAX) {
                throw ValidationException::withMessages([
                    'new_salary' => 'ยอดเงินที่คำนวณได้เกินขอบเขตที่ระบบรองรับ',
                ]);
            }

            if ($percent !== null && abs($percent) > self::PERCENT_MAX) {
                throw ValidationException::withMessages([
                    'new_salary' => 'เปอร์เซ็นต์การปรับที่คำนวณได้เกินขอบเขตที่ระบบรองรับ',
                ]);
            }

            $adjustment = SalaryAdjustment::create([
                'employee_id' => $employee->id,
                'old_salary' => $base, // ฐานจริงจาก DB เสมอ (ไม่ใช่ค่าจาก client)
                'new_salary' => $newSalary,
                'increase_amount' => $increase,
                'increase_percent' => $percent,
                'adjustment_date' => $validated['adjustment_date'],
                'adjustment_type' => $validated['adjustment_type'] ?? null,
                'note' => $validated['note'] ?? null,
                'created_by' => $userId,
                'updated_by' => $userId,
            ]);

            // อัปเดตฐานเงินเดือนให้พนักงาน — ใน transaction เดียวกับการสร้างประวัติ
            // ไม่แตะ latest_salary และไม่แตะ payrolls
            $employee->update([
                'salary' => $newSalary,
                'updated_by' => $userId,
            ]);

            return ['adjustment' => $adjustment];
        });

        if (isset($result['error'])) {
            return $result['error'];
        }

        $adjustment = $result['adjustment'];
        $adjustment->load(['employee' => fn ($q) => $q->with('prefix'), 'creator']);

        return response()->json([
            'message' => 'บันทึกการปรับฐานเงินเดือนเรียบร้อย',
            'data' => $adjustment,
        ], 201);
    }

}
