<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Import;
use App\Models\ReserveFundCalculation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReserveFundController extends Controller
{
    /**
     * สถานะบุคลากรที่นับในฐานเงินสำรอง — เฉพาะคนที่ปฏิบัติงานอยู่
     *
     * คนลาออก/ลาศึกษาต่อ/ลาเลี้ยงลูก ไม่ถูกนับ และแถวที่ผูกกับทะเบียนบุคลากรไม่ได้
     * (เลขบัตรประชาชนไม่ตรง) ก็ไม่ถูกนับเช่นกัน
     */
    protected const ACTIVE_EMPLOYEE_STATUSES = ['ปฏิบัติงานอยู่'];

    /**
     * นิพจน์ฐานคำนวณ = เงินเดือน + OT + เงินประจำตำแหน่ง + P4P
     */
    protected function incomeBaseExpression(): string
    {
        return 'COALESCE(p.salary, 0) + COALESCE(p.overtime, 0)'
            . ' + COALESCE(p.position_allowance, 0) + COALESCE(p.p4p_income, 0)';
    }

    /**
     * ดึงยอดรวมรายรับ จัดกลุ่มตาม ภารกิจ / กลุ่มงาน / งาน
     * นับเฉพาะบุคลากรที่ยัง "ปฏิบัติงานอยู่"
     *
     * ตัดแถวที่ไม่มีเลขบัตรประชาชนออก เพราะแถวเหล่านั้นคือ "แถวรวมยอด" ท้ายไฟล์ payroll
     * ไม่ใช่บุคลากรจริง ถ้านับด้วยจะทำให้ฐานคำนวณพองเป็นสองเท่า
     */
    protected function queryRows(?int $importId)
    {
        $incomeBase = $this->incomeBaseExpression();

        return DB::table('payrolls as p')
            ->leftJoin('employees as e', 'e.citizen_id', '=', 'p.citizen_id')
            ->leftJoin('duties as d', 'd.id', '=', 'e.duty_id')
            ->leftJoin('groups as g', 'g.id', '=', 'e.group_id')
            ->leftJoin('works as w', 'w.id', '=', 'e.work_id')
            ->leftJoin('employee_statuses as es', 'es.id', '=', 'e.status_id')
            ->when($importId, fn ($q) => $q->where('p.import_id', $importId))
            ->whereNotNull('p.citizen_id')
            ->where('p.citizen_id', '!=', '')
            ->whereIn('es.name', self::ACTIVE_EMPLOYEE_STATUSES)
            ->select(
                'd.id as duty_id',
                'd.name as duty_name',
                'g.id as group_id',
                'g.name as group_name',
                'w.id as work_id',
                'w.name as work_name',
                DB::raw("SUM({$incomeBase}) as income_base"),
                DB::raw('SUM(COALESCE(p.salary, 0)) as salary'),
                DB::raw('SUM(COALESCE(p.overtime, 0)) as overtime'),
                DB::raw('SUM(COALESCE(p.position_allowance, 0)) as position_allowance'),
                DB::raw('SUM(COALESCE(p.p4p_income, 0)) as p4p_income'),
                DB::raw('COUNT(*) as employee_count')
            )
            ->groupBy('d.id', 'd.name', 'g.id', 'g.name', 'w.id', 'w.name')
            ->get();
    }

    /**
     * สรุปยอดรวมทั้งหมดสำหรับบันทึกผลการคำนวณ
     */
    protected function aggregate(?int $importId): array
    {
        $rows = $this->queryRows($importId);

        $breakdown = [
            'salary'             => 0.0,
            'overtime'           => 0.0,
            'position_allowance' => 0.0,
            'p4p_income'         => 0.0,
        ];

        $totalIncomeBase = 0.0;
        $totalEmployees = 0;

        foreach ($rows as $row) {
            $totalIncomeBase += (float) $row->income_base;
            $totalEmployees += (int) $row->employee_count;

            foreach (array_keys($breakdown) as $key) {
                $breakdown[$key] += (float) $row->{$key};
            }
        }

        return [
            'rows'              => $rows,
            'total_income_base' => $totalIncomeBase,
            'total_employees'   => $totalEmployees,
            'breakdown'         => $breakdown,
        ];
    }

    /**
     * โครงสร้างยอดแยกตามภารกิจ (เก็บเป็น snapshot ใน JSON)
     */
    protected function buildDutyBreakdown($rows, float $rate): array
    {
        $duties = [];

        foreach ($rows as $row) {
            $key = $row->duty_id ?? 'none';

            if (!isset($duties[$key])) {
                $duties[$key] = [
                    'duty_id'        => $row->duty_id,
                    'duty_name'      => $row->duty_name ?? 'ไม่ระบุภารกิจ',
                    'income_base'    => 0.0,
                    'employee_count' => 0,
                ];
            }

            $duties[$key]['income_base'] += (float) $row->income_base;
            $duties[$key]['employee_count'] += (int) $row->employee_count;
        }

        return array_values(array_map(function ($duty) use ($rate) {
            $duty['reserve_amount'] = round($duty['income_base'] * $rate, 2);
            $duty['income_base'] = round($duty['income_base'], 2);

            return $duty;
        }, $duties));
    }

    /**
     * ยอดเงินสำรองสะสมของปีงบประมาณ = รวมทุกงวดที่ยืนยันแล้ว
     *
     * นับงวดละ 1 รายการ (unique fiscal_year + period_month) จึงไม่นับซ้ำ
     */
    protected function accumulated(int $fiscalYear): array
    {
        $confirmed = ReserveFundCalculation::query()
            ->forFiscalYear($fiscalYear)
            ->confirmed()
            ->with('import:id,file_name')
            ->get();

        $sorted = $confirmed
            ->sortBy(fn ($row) => ReserveFundCalculation::fiscalMonthOrder((int) $row->period_month))
            ->values();

        $periods = $sorted->map(fn ($row) => [
            'id'                => $row->id,
            'period_month'      => $row->period_month,
            'period_year'       => $row->period_year,
            'period_label'      => ReserveFundCalculation::monthLabel($row->period_month),
            'percent'           => (float) $row->percent,
            'total_income_base' => (float) $row->total_income_base,
            'total_reserve'     => (float) $row->total_reserve,
            'total_employees'   => $row->total_employees,
            'import_id'         => $row->import_id,
            'import_name'       => $row->import?->file_name,
            'confirmed_at'      => $row->confirmed_at,
        ])->all();

        return [
            'fiscal_year'             => $fiscalYear,
            'confirmed_periods'       => count($periods),
            'total_periods'           => count(ReserveFundCalculation::fiscalMonths()),
            'total_income_base'       => round($confirmed->sum(fn ($r) => (float) $r->total_income_base), 2),
            'total_reserve'           => round($confirmed->sum(fn ($r) => (float) $r->total_reserve), 2),
            'periods'                 => $periods,
        ];
    }

    // GET /api/hr/reserve-fund/imports
    // รายชื่อ import ที่มีข้อมูล payroll (ใช้เลือกช่วงข้อมูล)
    public function imports(): JsonResponse
    {
        $imports = Import::whereHas('payrolls')
            ->orderBy('created_at', 'desc')
            ->get(['id', 'file_name', 'created_at']);

        return response()->json([
            'data' => $imports,
        ]);
    }

    // GET /api/hr/reserve-fund?import_id=&percent=
    // คำนวณเงินสำรองตามเปอร์เซ็นต์ที่ระบุ จากฐานรายรับ
    // (เงินเดือน + ล่วงเวลา + เงินประจำตำแหน่ง + P4P)
    // จัดกลุ่มตาม ภารกิจ (duty) → กลุ่มงาน (group) → งาน (work)
    //
    // percent เป็นค่าที่ผู้ใช้ต้องระบุ ถ้าไม่ระบุจะยังไม่คำนวณ (reserve = null)
    public function summary(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'import_id'    => 'nullable|integer|exists:imports,id',
            'percent'      => 'nullable|numeric|min:0|max:100',
            'fiscal_year'  => 'nullable|integer|min:2500|max:2700',
            'period_month' => 'nullable|integer|min:1|max:12',
        ], [
            'percent.numeric' => 'เปอร์เซ็นต์ต้องเป็นตัวเลข',
            'percent.min'     => 'เปอร์เซ็นต์ต้องไม่น้อยกว่า 0',
            'percent.max'     => 'เปอร์เซ็นต์ต้องไม่เกิน 100',
        ]);

        // ไม่มีค่าเริ่มต้น — ผู้ใช้ต้องกรอกเปอร์เซ็นต์เอง
        $percent = isset($validated['percent']) ? (float) $validated['percent'] : null;
        $rate = $percent !== null ? $percent / 100 : null;

        // ใช้ import ล่าสุดถ้าไม่ระบุ
        $importId = $validated['import_id']
            ?? DB::table('payrolls')->max('import_id');

        $import = $importId ? Import::find($importId) : null;

        $rows = $this->queryRows($importId);

        $duties = [];
        $totalIncomeBase = 0.0;
        $totalEmployees = 0;
        $totalBreakdown = [
            'salary'             => 0.0,
            'overtime'           => 0.0,
            'position_allowance' => 0.0,
            'p4p_income'         => 0.0,
        ];

        foreach ($rows as $row) {
            $incomeBaseSum = (float) $row->income_base;
            $count = (int) $row->employee_count;

            $totalIncomeBase += $incomeBaseSum;
            $totalEmployees += $count;

            foreach (array_keys($totalBreakdown) as $key) {
                $totalBreakdown[$key] += (float) $row->{$key};
            }

            $dutyKey = $row->duty_id ?? 'none';

            if (!isset($duties[$dutyKey])) {
                $duties[$dutyKey] = [
                    'id' => $row->duty_id,
                    'name' => $row->duty_name ?? 'ไม่ระบุภารกิจ',
                    'income_base' => 0.0,
                    'reserve_amount' => 0.0,
                    'employee_count' => 0,
                    'works' => [],
                ];
            }

            $duties[$dutyKey]['income_base'] += $incomeBaseSum;
            $duties[$dutyKey]['employee_count'] += $count;

            $workKey = ($row->work_id ?? 'none') . '|' . ($row->group_id ?? 'none');

            if (!isset($duties[$dutyKey]['works'][$workKey])) {
                $duties[$dutyKey]['works'][$workKey] = [
                    'id' => $row->work_id,
                    'group_id' => $row->group_id,
                    'group_name' => $row->group_name ?? 'ไม่ระบุกลุ่มงาน',
                    'name' => $row->work_name ?? 'ไม่ระบุงาน',
                    'income_base' => 0.0,
                    'reserve_amount' => 0.0,
                    'employee_count' => 0,
                    'salary' => 0.0,
                    'overtime' => 0.0,
                    'position_allowance' => 0.0,
                    'p4p_income' => 0.0,
                ];
            }

            $duties[$dutyKey]['works'][$workKey]['income_base'] += $incomeBaseSum;
            $duties[$dutyKey]['works'][$workKey]['employee_count'] += $count;

            foreach (array_keys($totalBreakdown) as $key) {
                $duties[$dutyKey]['works'][$workKey][$key] += (float) $row->{$key};
            }
        }

        // คำนวณเงินสำรองตามเปอร์เซ็นต์ที่ระบุ + จัดเรียง
        // ถ้าไม่ได้ระบุเปอร์เซ็นต์ reserve_amount จะเป็น null
        $dutyList = [];
        foreach ($duties as $duty) {
            $duty['reserve_amount'] = $rate !== null
                ? round($duty['income_base'] * $rate, 2)
                : null;
            $duty['income_base'] = round($duty['income_base'], 2);

            $duty['works'] = array_values(array_map(function ($work) use ($rate) {
                $work['reserve_amount'] = $rate !== null
                    ? round($work['income_base'] * $rate, 2)
                    : null;
                $work['income_base'] = round($work['income_base'], 2);

                foreach (['salary', 'overtime', 'position_allowance', 'p4p_income'] as $key) {
                    $work[$key] = round($work[$key], 2);
                }

                return $work;
            }, $duty['works']));

            // จัดเรียงงานตามชื่อ
            usort($duty['works'], fn ($a, $b) => strcmp($a['name'], $b['name']));

            $dutyList[] = $duty;
        }

        usort($dutyList, fn ($a, $b) => strcmp($a['name'], $b['name']));

        $fiscalYear = isset($validated['fiscal_year'])
            ? (int) $validated['fiscal_year']
            : ReserveFundCalculation::currentFiscalYear();

        $periodMonth = isset($validated['period_month'])
            ? (int) $validated['period_month']
            : null;

        // ผลการคำนวณที่เคยบันทึกไว้
        // ระบุงวด → ดูงวดนั้น / ไม่ระบุ → ย้อนไปดูตามชุดข้อมูลเดิม (คงพฤติกรรมเดิม)
        $saved = ReserveFundCalculation::query()
            ->where('fiscal_year', $fiscalYear)
            ->when(
                $periodMonth !== null,
                fn ($q) => $q->where('period_month', $periodMonth),
                fn ($q) => $q->where('import_id', $importId)
            )
            ->first();

        return response()->json([
            'data' => [
                'duties' => $dutyList,
            ],
            'summary' => [
                'percent' => $percent,
                'fiscal_year' => $fiscalYear,
                'period_month' => $periodMonth,
                'period_label' => ReserveFundCalculation::monthLabel($periodMonth),
                'total_income_base' => round($totalIncomeBase, 2),
                'total_reserve' => $rate !== null ? round($totalIncomeBase * $rate, 2) : null,
                'total_employees' => $totalEmployees,
                'income_breakdown' => array_map(fn ($v) => round($v, 2), $totalBreakdown),
                'import_id' => $importId,
                'import_name' => $import?->file_name,
                'imported_at' => $import?->created_at,
                'saved' => $saved ? [
                    'id'                => $saved->id,
                    'percent'           => (float) $saved->percent,
                    'fiscal_year'       => $saved->fiscal_year,
                    'period_month'      => $saved->period_month,
                    'period_year'       => $saved->period_year,
                    'period_label'      => ReserveFundCalculation::monthLabel($saved->period_month),
                    'status'            => $saved->status,
                    'confirmed_at'      => $saved->confirmed_at,
                    'total_income_base' => (float) $saved->total_income_base,
                    'total_reserve'     => (float) $saved->total_reserve,
                    'total_employees'   => $saved->total_employees,
                    'note'              => $saved->note,
                    'saved_at'          => $saved->updated_at,
                ] : null,
            ],
            // เงินสำรองสะสม: รวมทุกงวดที่ยืนยันแล้วในปีงบเดียวกัน
            'accumulated' => $this->accumulated($fiscalYear),
        ]);
    }

    /**
     * POST /api/hr/reserve-fund/calculations
     * บันทึกผลการคำนวณของงวด payroll หนึ่งงวด ภายใต้ปีงบประมาณ
     *
     * บันทึกซ้ำด้วยปีงบ + งวดเดิม = อัปเดตรายการนั้น (ไม่เกิดรายการซ้ำในยอดสะสม)
     * บันทึกเป็นสถานะ draft เสมอ — ต้องกดยืนยันอีกครั้งจึงจะถูกนับในยอดสะสม
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'import_id'    => 'nullable|integer|exists:imports,id',
            'percent'      => 'required|numeric|min:0|max:100',
            'fiscal_year'  => 'nullable|integer|min:2500|max:2700',
            'period_month' => 'required|integer|min:1|max:12',
            'note'         => 'nullable|string|max:1000',
        ], [
            'percent.required'      => 'กรุณาระบุเปอร์เซ็นต์ที่ใช้คำนวณ',
            'percent.numeric'       => 'เปอร์เซ็นต์ต้องเป็นตัวเลข',
            'percent.min'           => 'เปอร์เซ็นต์ต้องไม่น้อยกว่า 0',
            'percent.max'           => 'เปอร์เซ็นต์ต้องไม่เกิน 100',
            'period_month.required' => 'กรุณาเลือกงวดเดือนของ Payroll',
            'period_month.min'      => 'งวดเดือนต้องอยู่ระหว่าง 1-12',
            'period_month.max'      => 'งวดเดือนต้องอยู่ระหว่าง 1-12',
        ]);

        $percent = (float) $validated['percent'];
        $rate = $percent / 100;

        $importId = $validated['import_id']
            ?? DB::table('payrolls')->max('import_id');

        $fiscalYear = isset($validated['fiscal_year'])
            ? (int) $validated['fiscal_year']
            : ReserveFundCalculation::currentFiscalYear();

        $periodMonth = (int) $validated['period_month'];
        $periodYear = ReserveFundCalculation::calendarYearOf($fiscalYear, $periodMonth);

        $aggregate = $this->aggregate($importId);

        if ($aggregate['total_employees'] === 0) {
            return response()->json([
                'message' => 'ไม่พบข้อมูล Payroll สำหรับคำนวณ จึงยังบันทึกไม่ได้',
            ], 422);
        }

        $userId = $request->user()?->id;

        $existing = ReserveFundCalculation::query()
            ->where('fiscal_year', $fiscalYear)
            ->where('period_month', $periodMonth)
            ->first();

        // งวดที่ยืนยันแล้ว ไม่ถูกเขียนทับโดยอัตโนมัติ — ต้องยกเลิกการยืนยันก่อน
        if ($existing && $existing->isConfirmed()) {
            return response()->json([
                'message' => 'งวด ' . ReserveFundCalculation::monthLabel($periodMonth)
                    . " ของปีงบประมาณ {$fiscalYear} ยืนยันแล้ว หากต้องการแก้ไข กรุณายกเลิกการยืนยันก่อน",
                'data'    => $existing->load('import:id,file_name'),
            ], 409);
        }

        $calculation = ReserveFundCalculation::updateOrCreate(
            [
                'fiscal_year'  => $fiscalYear,
                'period_month' => $periodMonth,
            ],
            [
                'import_id'         => $importId,
                'period_year'       => $periodYear,
                'percent'           => $percent,
                'total_income_base' => round($aggregate['total_income_base'], 2),
                'total_reserve'     => round($aggregate['total_income_base'] * $rate, 2),
                'total_employees'   => $aggregate['total_employees'],
                'status'            => ReserveFundCalculation::STATUS_DRAFT,
                'income_breakdown'  => array_map(fn ($v) => round($v, 2), $aggregate['breakdown']),
                'duty_breakdown'    => $this->buildDutyBreakdown($aggregate['rows'], $rate),
                'note'              => $validated['note'] ?? null,
                'updated_by'        => $userId,
            ]
        );

        if ($calculation->wasRecentlyCreated) {
            $calculation->forceFill(['created_by' => $userId])->save();
        }

        $monthLabel = ReserveFundCalculation::monthLabel($periodMonth);

        return response()->json([
            'message'     => "บันทึกผลการคำนวณงวด {$monthLabel} ปีงบประมาณ {$fiscalYear} สำเร็จ",
            'data'        => $calculation->fresh()->load('import:id,file_name'),
            'accumulated' => $this->accumulated($fiscalYear),
        ], $calculation->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * POST /api/hr/reserve-fund/calculations/{calculation}/confirm
     * ยืนยันงวด — หลังยืนยันจึงถูกนับในยอดเงินสำรองสะสมของปีงบ
     */
    public function confirm(Request $request, ReserveFundCalculation $calculation): JsonResponse
    {
        if ($calculation->period_month === null) {
            return response()->json([
                'message' => 'รายการนี้ยังไม่ได้ระบุงวดเดือน จึงยืนยันไม่ได้ กรุณาบันทึกผลการคำนวณใหม่พร้อมเลือกงวด',
            ], 422);
        }

        if (! $calculation->isConfirmed()) {
            $calculation->forceFill([
                'status'       => ReserveFundCalculation::STATUS_CONFIRMED,
                'confirmed_at' => now(),
                'confirmed_by' => $request->user()?->id,
            ])->save();
        }

        $monthLabel = ReserveFundCalculation::monthLabel($calculation->period_month);

        return response()->json([
            'message'     => "ยืนยันงวด {$monthLabel} ปีงบประมาณ {$calculation->fiscal_year} แล้ว",
            'data'        => $calculation->fresh()->load('import:id,file_name'),
            'accumulated' => $this->accumulated($calculation->fiscal_year),
        ]);
    }

    /**
     * POST /api/hr/reserve-fund/calculations/{calculation}/unconfirm
     * ยกเลิกการยืนยัน — ถอนงวดออกจากยอดสะสม โดยไม่ลบข้อมูลผลคำนวณ
     */
    public function unconfirm(ReserveFundCalculation $calculation): JsonResponse
    {
        $calculation->forceFill([
            'status'       => ReserveFundCalculation::STATUS_DRAFT,
            'confirmed_at' => null,
            'confirmed_by' => null,
        ])->save();

        $monthLabel = ReserveFundCalculation::monthLabel($calculation->period_month);

        return response()->json([
            'message'     => "ยกเลิกการยืนยันงวด {$monthLabel} แล้ว (ข้อมูลผลการคำนวณยังอยู่)",
            'data'        => $calculation->fresh()->load('import:id,file_name'),
            'accumulated' => $this->accumulated($calculation->fiscal_year),
        ]);
    }

    /**
     * GET /api/hr/reserve-fund/accumulated?fiscal_year=
     * ยอดเงินสำรองสะสมของปีงบประมาณ (รวมทุกงวดที่ยืนยันแล้ว)
     */
    public function accumulatedSummary(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'fiscal_year' => 'nullable|integer|min:2500|max:2700',
        ]);

        $fiscalYear = isset($validated['fiscal_year'])
            ? (int) $validated['fiscal_year']
            : ReserveFundCalculation::currentFiscalYear();

        return response()->json([
            'data' => $this->accumulated($fiscalYear),
        ]);
    }

    /**
     * GET /api/hr/reserve-fund/fiscal-years
     * ปีงบประมาณที่มีผลการคำนวณบันทึกไว้แล้ว (ใหม่สุดก่อน)
     */
    public function fiscalYears(): JsonResponse
    {
        $years = ReserveFundCalculation::query()
            ->distinct()
            ->orderByDesc('fiscal_year')
            ->pluck('fiscal_year')
            ->map(fn ($year) => (int) $year)
            ->values();

        return response()->json([
            'data' => $years,
        ]);
    }

    /**
     * GET /api/hr/reserve-fund/calculations
     * ประวัติผลการคำนวณที่บันทึกไว้ (ล่าสุดก่อน)
     */
    public function calculations(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'fiscal_year' => 'nullable|integer|min:2500|max:2700',
            'status'      => 'nullable|in:draft,confirmed',
        ]);

        $calculations = ReserveFundCalculation::with([
            'import:id,file_name',
            'creator:id,name',
            'updater:id,name',
            'confirmer:id,name',
        ])
            ->when(
                $validated['fiscal_year'] ?? null,
                fn ($q, $year) => $q->where('fiscal_year', $year)
            )
            ->when(
                $validated['status'] ?? null,
                fn ($q, $status) => $q->where('status', $status)
            )
            ->orderByDesc('fiscal_year')
            ->orderByDesc('updated_at')
            ->paginate(20);

        return response()->json($calculations);
    }
}