<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\SalaryAdjustment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SalaryAdjustmentController extends Controller
{
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
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'old_salary' => 'required|numeric|min:0',
            'new_salary' => 'required|numeric|min:0',
            'adjustment_date' => 'required|date',
            'adjustment_type' => 'nullable|string|max:50',
            'note' => 'nullable|string|max:1000',
        ]);

        $oldSalary = (float) $validated['old_salary'];
        $newSalary = (float) $validated['new_salary'];
        $increase = round($newSalary - $oldSalary, 2);
        $percent = $oldSalary > 0
            ? round(($increase / $oldSalary) * 100, 2)
            : null;

        $adjustment = DB::transaction(function () use ($request, $validated, $oldSalary, $newSalary, $increase, $percent) {
            $adjustment = SalaryAdjustment::create([
                'employee_id' => $validated['employee_id'],
                'old_salary' => $oldSalary,
                'new_salary' => $newSalary,
                'increase_amount' => $increase,
                'increase_percent' => $percent,
                'adjustment_date' => $validated['adjustment_date'],
                'adjustment_type' => $validated['adjustment_type'] ?? null,
                'note' => $validated['note'] ?? null,
                'created_by' => $request->user()?->id,
                'updated_by' => $request->user()?->id,
            ]);

            // อัปเดตเงินเดือนล่าสุดให้พนักงาน
            Employee::where('id', $validated['employee_id'])->update([
                'salary' => $newSalary,
                'updated_by' => $request->user()?->id,
            ]);

            return $adjustment;
        });

        $adjustment->load(['employee' => fn ($q) => $q->with('prefix'), 'creator']);

        return response()->json([
            'message' => 'บันทึกการปรับฐานเงินเดือนเรียบร้อย',
            'data' => $adjustment,
        ], 201);
    }

}
