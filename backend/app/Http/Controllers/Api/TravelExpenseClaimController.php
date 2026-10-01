<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTravelExpenseClaimRequest;
use App\Http\Requests\UpdateTravelExpenseClaimRequest;
use App\Models\Employee;
use App\Models\TravelExpenseClaim;
use App\Models\TravelExpenseClaimItem;
use App\Services\TravelExpenseClaimExporter;
use App\Support\ThaiFiscalYear;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * ใบเบิกค่าใช้จ่ายเดินทางไปราชการ
 *
 * ทุก endpoint กรองตามปีงบประมาณที่ส่งมาจาก Header ของ frontend
 */
class TravelExpenseClaimController extends Controller
{
    /** ความสัมพันธ์ที่ใช้แสดงผลเอกสาร */
    protected const RELATIONS = [
        'items',
        // ใช้ดึงเลขบัตรประชาชนของผู้เบิกมาแสดง
        'items.employee:id,citizen_id',
        'creator:id,name',
        'confirmer:id,name',
        'canceller:id,name',
    ];

    /** สถานะบุคลากรที่ไม่ให้เลือกเป็นผู้เบิก */
    protected const EXCLUDED_EMPLOYEE_STATUSES = ['ลาออก'];

    /**
     * GET /api/finance/travel-expense-claims
     * รายการเอกสารของปีงบประมาณที่เลือก
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'fiscal_year' => ['nullable', 'integer', 'min:' . ThaiFiscalYear::MIN, 'max:' . ThaiFiscalYear::MAX],
            'claim_month' => ['nullable', 'integer', 'min:1', 'max:12'],
            'status'      => ['nullable', 'in:draft,confirmed,cancelled'],
            'search'      => ['nullable', 'string', 'max:255'],
            'per_page'    => ['nullable', 'integer'],
        ]);

        $fiscalYear = (int) ($validated['fiscal_year'] ?? ThaiFiscalYear::current());

        $perPage = (int) ($validated['per_page'] ?? 10);
        $perPage = in_array($perPage, [10, 25, 50, 100], true) ? $perPage : 10;

        $claims = TravelExpenseClaim::query()
            ->forFiscalYear($fiscalYear)
            ->with(self::RELATIONS)
            ->withCount('items')
            ->withSum('items', 'total_amount')
            ->when(
                $validated['claim_month'] ?? null,
                fn ($q, $month) => $q->whereMonth('claim_period', $month)
            )
            ->when(
                $validated['status'] ?? null,
                fn ($q, $status) => $q->where('status', $status)
            )
            ->when($validated['search'] ?? null, function ($q, $term) {
                $q->where(function ($sub) use ($term) {
                    $sub->where('document_no', 'like', "%{$term}%")
                        ->orWhereHas('items', function ($iq) use ($term) {
                            $iq->where('first_name', 'like', "%{$term}%")
                                ->orWhere('last_name', 'like', "%{$term}%")
                                ->orWhere('pid', 'like', "%{$term}%");
                        });
                });
            })
            ->orderByDesc('claim_period')
            ->orderByDesc('id')
            ->paginate($perPage);

        $claims->getCollection()->transform(fn ($claim) => $this->presentSummary($claim));

        return response()->json($claims);
    }

    /**
     * GET /api/finance/travel-expense-claims/{claim}
     */
    public function show(TravelExpenseClaim $claim): JsonResponse
    {
        return response()->json([
            'data' => $this->present($claim->load(self::RELATIONS)),
        ]);
    }

    /**
     * POST /api/finance/travel-expense-claims
     * บันทึกร่าง หรือ ยืนยันและบันทึกในครั้งเดียว
     */
    public function store(StoreTravelExpenseClaimRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $status = $validated['status'] ?? TravelExpenseClaim::STATUS_DRAFT;
        $userId = $request->user()?->id;

        $claim = DB::transaction(function () use ($validated, $status, $userId) {
            $fiscalYear = (int) $validated['fiscal_year'];
            $confirmed = $status === TravelExpenseClaim::STATUS_CONFIRMED;

            $claim = TravelExpenseClaim::create([
                'document_no'       => TravelExpenseClaim::nextDocumentNo($fiscalYear),
                'fiscal_year'       => $fiscalYear,
                'claim_period'      => $this->normalizePeriod($validated['claim_period']),
                'expense_category'  => $validated['expense_category'],
                'organization_name' => $validated['organization_name'] ?? config('travel_expense.organization_name'),
                'note'              => $validated['note'] ?? null,
                'status'            => $status,
                'created_by'        => $userId,
                'confirmed_by'      => $confirmed ? $userId : null,
                'confirmed_at'      => $confirmed ? now() : null,
            ]);

            $this->syncItems($claim, $validated['items'] ?? []);

            return $claim;
        });

        return response()->json([
            'message' => $status === TravelExpenseClaim::STATUS_CONFIRMED
                ? "ยืนยันใบเบิก {$claim->document_no} เรียบร้อย"
                : "บันทึกร่างใบเบิก {$claim->document_no} เรียบร้อย",
            'data' => $this->present($claim->fresh()->load(self::RELATIONS)),
        ], 201);
    }

    /**
     * PUT /api/finance/travel-expense-claims/{claim}
     * แก้ไขได้เฉพาะเอกสารร่าง
     */
    public function update(UpdateTravelExpenseClaimRequest $request, TravelExpenseClaim $claim): JsonResponse
    {
        if (! $claim->isEditable()) {
            return response()->json([
                'message' => $claim->isConfirmed()
                    ? 'เอกสารนี้ยืนยันแล้ว จึงแก้ไขไม่ได้'
                    : 'เอกสารนี้ถูกยกเลิกแล้ว จึงแก้ไขไม่ได้',
            ], 409);
        }

        $validated = $request->validated();
        $status = $validated['status'] ?? TravelExpenseClaim::STATUS_DRAFT;
        $userId = $request->user()?->id;

        DB::transaction(function () use ($claim, $validated, $status, $userId) {
            $confirmed = $status === TravelExpenseClaim::STATUS_CONFIRMED;

            $claim->update([
                'fiscal_year'       => (int) $validated['fiscal_year'],
                'claim_period'      => $this->normalizePeriod($validated['claim_period']),
                'expense_category'  => $validated['expense_category'],
                'organization_name' => $validated['organization_name'] ?? $claim->organization_name,
                'note'              => $validated['note'] ?? null,
                'status'            => $status,
                'confirmed_by'      => $confirmed ? $userId : null,
                'confirmed_at'      => $confirmed ? now() : null,
            ]);

            $this->syncItems($claim, $validated['items'] ?? []);
        });

        return response()->json([
            'message' => $status === TravelExpenseClaim::STATUS_CONFIRMED
                ? "ยืนยันใบเบิก {$claim->document_no} เรียบร้อย"
                : 'บันทึกร่างเรียบร้อย',
            'data' => $this->present($claim->fresh()->load(self::RELATIONS)),
        ]);
    }

    /**
     * POST /api/finance/travel-expense-claims/{claim}/confirm
     */
    public function confirm(Request $request, TravelExpenseClaim $claim): JsonResponse
    {
        if ($claim->isCancelled()) {
            return response()->json(['message' => 'เอกสารนี้ถูกยกเลิกแล้ว จึงยืนยันไม่ได้'], 409);
        }

        if ($claim->items()->count() === 0) {
            return response()->json([
                'message' => 'ต้องมีรายการผู้เบิกอย่างน้อย 1 รายการก่อนยืนยันเอกสาร',
            ], 422);
        }

        if (! $claim->isConfirmed()) {
            $claim->update([
                'status'       => TravelExpenseClaim::STATUS_CONFIRMED,
                'confirmed_by' => $request->user()?->id,
                'confirmed_at' => now(),
            ]);
        }

        return response()->json([
            'message' => "ยืนยันใบเบิก {$claim->document_no} เรียบร้อย",
            'data'    => $this->present($claim->fresh()->load(self::RELATIONS)),
        ]);
    }

    /**
     * POST /api/finance/travel-expense-claims/{claim}/cancel
     * เปลี่ยนสถานะเป็น cancelled — ไม่ลบข้อมูลจริง
     */
    public function cancel(Request $request, TravelExpenseClaim $claim): JsonResponse
    {
        $validated = $request->validate([
            'cancel_reason' => ['nullable', 'string', 'max:500'],
        ]);

        if ($claim->isCancelled()) {
            return response()->json([
                'message' => 'เอกสารนี้ถูกยกเลิกไว้แล้ว',
                'data'    => $this->present($claim->load(self::RELATIONS)),
            ]);
        }

        $claim->update([
            'status'        => TravelExpenseClaim::STATUS_CANCELLED,
            'cancelled_by'  => $request->user()?->id,
            'cancelled_at'  => now(),
            'cancel_reason' => $validated['cancel_reason'] ?? null,
        ]);

        return response()->json([
            'message' => "ยกเลิกใบเบิก {$claim->document_no} แล้ว (ข้อมูลเอกสารยังอยู่ในระบบ)",
            'data'    => $this->present($claim->fresh()->load(self::RELATIONS)),
        ]);
    }

    /**
     * GET /api/finance/travel-expense-claims/{claim}/export
     * ส่งออก Excel — เฉพาะเอกสารที่ยืนยันแล้ว
     */
    public function export(TravelExpenseClaim $claim, TravelExpenseClaimExporter $exporter): BinaryFileResponse|JsonResponse
    {
        if (! $claim->isConfirmed()) {
            return response()->json([
                'message' => $claim->isCancelled()
                    ? 'เอกสารนี้ถูกยกเลิกแล้ว จึงส่งออกไม่ได้'
                    : 'ส่งออกได้เฉพาะเอกสารที่ยืนยันแล้ว',
            ], 422);
        }

        return $exporter->download($claim->load('items'));
    }

    /**
     * GET /api/finance/travel-expense-claims/employees
     * ค้นหาบุคลากรจากระบบเดิม (เลขบัตรประชาชน, ชื่อ, นามสกุล, ตำแหน่ง)
     *
     * ไม่รวมบุคลากรที่สถานะเป็น "ลาออก"
     */
    public function employees(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'limit'  => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $term = $validated['search'] ?? null;
        $limit = (int) ($validated['limit'] ?? 30);

        $employees = Employee::query()
            ->with(['prefix:id,name', 'position:id,name'])
            // ตัดสถานะลาออกออกจากตัวเลือก (สถานะว่างยังเลือกได้)
            ->whereDoesntHave('status', function ($sq) {
                $sq->whereIn('name', self::EXCLUDED_EMPLOYEE_STATUSES);
            })
            ->when($term, function ($q) use ($term) {
                $q->where(function ($sub) use ($term) {
                    $sub->where('citizen_id', 'like', "%{$term}%")
                        ->orWhere('first_name', 'like', "%{$term}%")
                        ->orWhere('last_name', 'like', "%{$term}%")
                        ->orWhereHas('position', fn ($pq) => $pq->where('name', 'like', "%{$term}%"));
                });
            })
            ->orderBy('first_name')
            ->limit($limit)
            ->get()
            ->map(fn ($employee) => [
                'id'            => $employee->id,
                'pid'           => $employee->employee_id,
                'citizen_id'    => $employee->citizen_id,
                'prefix_name'   => $employee->prefix?->name,
                'first_name'    => $employee->first_name,
                'last_name'     => $employee->last_name,
                'full_name'     => $employee->full_name,
                'position_name' => $employee->position?->name,
            ]);

        return response()->json(['data' => $employees]);
    }

    /**
     * GET /api/finance/travel-expense-claims/options
     * ค่าเริ่มต้นของฟอร์ม + เดือนของปีงบประมาณที่เลือก
     */
    public function options(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'fiscal_year' => ['nullable', 'integer', 'min:' . ThaiFiscalYear::MIN, 'max:' . ThaiFiscalYear::MAX],
        ]);

        $fiscalYear = (int) ($validated['fiscal_year'] ?? ThaiFiscalYear::current());

        $months = array_map(function ($month) use ($fiscalYear) {
            $buddhistYear = ThaiFiscalYear::calendarYearOf($fiscalYear, $month);

            return [
                'month'         => $month,
                'label'         => ThaiFiscalYear::monthLabel($month),
                'calendar_year' => $buddhistYear,
                // ค่าที่ส่งกลับมาเป็น claim_period (ค.ศ.)
                'value'         => sprintf('%04d-%02d-01', $buddhistYear - 543, $month),
            ];
        }, ThaiFiscalYear::MONTHS);

        return response()->json([
            'data' => [
                'fiscal_year'              => $fiscalYear,
                'default_expense_category' => TravelExpenseClaim::DEFAULT_CATEGORY,
                // ตัวเลือกใน dropdown: เดินทางไปราชการ / เดินทางไปราชการโดยฝึกอบรม
                'expense_categories'       => [
                    ['value' => TravelExpenseClaim::CATEGORY_TRAVEL, 'label' => 'เดินทางไปราชการ'],
                    ['value' => TravelExpenseClaim::CATEGORY_TRAINING, 'label' => 'เดินทางไปราชการโดยฝึกอบรม'],
                ],
                'organization_name'        => config('travel_expense.organization_name'),
                'months'                   => $months,
            ],
        ]);
    }

    // ========== Internal helpers ==========

    /** เก็บเดือนที่เบิกเป็นวันที่ 1 ของเดือนเสมอ */
    protected function normalizePeriod(string $period): string
    {
        return CarbonImmutable::parse($period)->startOfMonth()->toDateString();
    }

    /**
     * เขียนรายการย่อยใหม่ทั้งชุด (เรียกภายใน transaction เท่านั้น)
     * total_amount คำนวณฝั่ง backend ไม่เชื่อค่าจาก frontend
     */
    protected function syncItems(TravelExpenseClaim $claim, array $items): void
    {
        $claim->items()->delete();

        foreach (array_values($items) as $index => $row) {
            $claim->items()->create([
                'employee_id'           => $row['employee_id'] ?? null,
                'pid'                   => $row['pid'] ?? null,
                'first_name'            => $row['first_name'],
                'last_name'             => $row['last_name'],
                'position_name'         => $row['position_name'] ?? null,
                'allowance_amount'      => round((float) ($row['allowance_amount'] ?? 0), 2),
                'accommodation_amount'  => round((float) ($row['accommodation_amount'] ?? 0), 2),
                'transportation_amount' => round((float) ($row['transportation_amount'] ?? 0), 2),
                'other_amount'          => round((float) ($row['other_amount'] ?? 0), 2),
                'total_amount'          => TravelExpenseClaimItem::computeTotal($row),
                'sort_order'            => $index + 1,
            ]);
        }
    }

    /** ข้อมูลเอกสารแบบย่อสำหรับหน้ารายการ */
    protected function presentSummary(TravelExpenseClaim $claim): array
    {
        return [
            'id'               => $claim->id,
            'document_no'      => $claim->document_no,
            'fiscal_year'      => $claim->fiscal_year,
            'claim_period'     => $claim->claim_period?->toDateString(),
            'period_label'     => $claim->claim_period ? ThaiFiscalYear::periodLabel($claim->claim_period) : null,
            'expense_category' => $claim->expense_category,
            'status'           => $claim->status,
            'is_editable'      => $claim->isEditable(),
            'items_count'      => $claim->items_count ?? $claim->items->count(),
            'total_amount'     => round((float) ($claim->items_sum_total_amount ?? 0), 2),
            'created_by_name'  => $claim->creator?->name,
            'created_at'       => $claim->created_at,
        ];
    }

    /** ข้อมูลเอกสารเต็มพร้อมยอดรวม */
    protected function present(TravelExpenseClaim $claim): array
    {
        $items = $claim->items;

        return [
            'id'                => $claim->id,
            'document_no'       => $claim->document_no,
            'fiscal_year'       => $claim->fiscal_year,
            'claim_period'      => $claim->claim_period?->toDateString(),
            'claim_month'       => $claim->claim_period?->month,
            'period_label'      => $claim->claim_period ? ThaiFiscalYear::periodLabel($claim->claim_period) : null,
            'expense_category'  => $claim->expense_category,
            'organization_name' => $claim->organization_name,
            'note'              => $claim->note,
            'status'            => $claim->status,
            'is_editable'       => $claim->isEditable(),
            'created_by_name'   => $claim->creator?->name,
            'confirmed_by_name' => $claim->confirmer?->name,
            'confirmed_at'      => $claim->confirmed_at,
            'cancelled_by_name' => $claim->canceller?->name,
            'cancelled_at'      => $claim->cancelled_at,
            'cancel_reason'     => $claim->cancel_reason,
            'created_at'        => $claim->created_at,
            'items'             => $items->map(fn ($item) => [
                'id'                    => $item->id,
                'employee_id'           => $item->employee_id,
                'pid'                   => $item->pid,
                // เลขบัตรประชาชนดึงจากบุคลากรที่ผูกไว้ (ไม่เก็บซ้ำในตารางรายการ)
                'citizen_id'            => $item->employee?->citizen_id,
                'first_name'            => $item->first_name,
                'last_name'             => $item->last_name,
                'position_name'         => $item->position_name,
                'allowance_amount'      => (float) $item->allowance_amount,
                'accommodation_amount'  => (float) $item->accommodation_amount,
                'transportation_amount' => (float) $item->transportation_amount,
                'other_amount'          => (float) $item->other_amount,
                'total_amount'          => (float) $item->total_amount,
            ])->values(),
            'totals' => [
                'people'                => $items->count(),
                'allowance_amount'      => round((float) $items->sum('allowance_amount'), 2),
                'accommodation_amount'  => round((float) $items->sum('accommodation_amount'), 2),
                'transportation_amount' => round((float) $items->sum('transportation_amount'), 2),
                'other_amount'          => round((float) $items->sum('other_amount'), 2),
                'total_amount'          => round((float) $items->sum('total_amount'), 2),
            ],
        ];
    }
}
