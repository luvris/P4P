<?php

namespace App\Services;

use App\Models\TravelExpenseClaim;
use App\Support\ThaiFiscalYear;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * สรุปผลการเบิกค่าใช้จ่าย — ยอดแยกตาม ภารกิจ → กลุ่มงาน → งาน
 *
 * หลักการคำนวณ:
 *  - ผู้เบิกจริงคือรายการใน travel_expense_claim_items (ไม่ใช่ created_by ผู้บันทึกเอกสาร)
 *  - รวมบุคคลด้วยรหัส (employee_id, fallback pid) ไม่ใช่ชื่อ — ชื่อซ้ำไม่ถูกรวมกัน
 *  - ยอดเงินจัดสรรตามรายการที่ผูกกับแต่ละงานผ่านสังกัดปัจจุบันของบุคลากร
 *    ใบเบิกเดียวที่มีคนหลายงานถูกแบ่งตามรายคนโดยอัตโนมัติ ไม่นับยอดทั้งใบซ้ำทุกงาน
 *  - รายการที่ระบุสังกัดไม่ได้ รวมไว้ในหมวด "ไม่ระบุสังกัด"
 *  - "ผู้เบิกสูงสุด" คือคนที่ยอดเงินรวมสูงสุด (ไม่ใช่จำนวนใบมากที่สุด)
 *    ถ้าเสมอกันแสดงทุกคนที่ได้อันดับสูงสุดร่วมกัน
 */
class TravelExpenseClaimSummaryService
{
    public const LEVEL_DUTY = 'duty';
    public const LEVEL_GROUP = 'group';
    public const LEVEL_WORK = 'work';

    protected const UNASSIGNED_LABEL = 'ไม่ระบุสังกัด';

    /**
     * สรุปยอดตามระดับที่โฟกัส
     *
     *  - level=duty  → buckets คือภารกิจทุกภารกิจ (หน้าแรกของดริลดาวน์)
     *  - level=group → buckets คือกลุ่มงานภายใต้ duty_id ที่เลือก
     *  - level=work  → buckets คืองานภายใต้ group_id ที่เลือก
     *    (เลือกงานเพิ่มด้วย work_id เพื่อดูเฉพาะงานนั้น — buckets จะเหลืองานเดียว)
     *
     * ทุกระดับคำนวณใหม่จาก query ตามตัวกรองทั้งชุด เช่น กลับไปดูกลุ่มงาน B
     * ได้ยอดจากทุกงานภายในกลุ่ม B ไม่ใช่ผลของงานที่เคยเปิดค้างไว้
     *
     * totals / buckets / ranking คำนวณจาก query ฐานเดียวกัน จึงใช้ชุดข้อมูลเดียวกันเสมอ
     */
    public function summary(array $filters): array
    {
        $fiscalYear = (int) ($filters['fiscal_year'] ?? ThaiFiscalYear::current());
        $level = $filters['level'] ?? self::LEVEL_DUTY;

        // ยกเลิก (cancelled) ไม่นับเสมอ — include_draft เปิดให้รวมใบร่างได้
        $statuses = ! empty($filters['include_draft'])
            ? [TravelExpenseClaim::STATUS_CONFIRMED, TravelExpenseClaim::STATUS_DRAFT]
            : [TravelExpenseClaim::STATUS_CONFIRMED];

        $base = $this->baseQuery($fiscalYear, $statuses, $filters);

        $ranking = $this->personRanking(clone $base);

        // แถวหมวดย่อยของระดับที่โฟกัส (ทุกระดับมีแถวย่อย: ภารกิจ/กลุ่มงาน/งาน)
        $buckets = $this->buckets(clone $base, $level);

        return [
            'fiscal_year' => $fiscalYear,
            'level'       => $level,
            'filters'     => [
                'duty_id'     => isset($filters['duty_id']) ? (int) $filters['duty_id'] : null,
                'group_id'    => isset($filters['group_id']) ? (int) $filters['group_id'] : null,
                'work_id'     => isset($filters['work_id']) ? (int) $filters['work_id'] : null,
                'period_from' => ! empty($filters['period_from'])
                    ? CarbonImmutable::parse($filters['period_from'])->startOfMonth()->toDateString()
                    : null,
                'period_to'   => ! empty($filters['period_to'])
                    ? CarbonImmutable::parse($filters['period_to'])->endOfMonth()->toDateString()
                    : null,
                'include_draft' => (bool) ($filters['include_draft'] ?? false),
            ],
            'totals'  => $this->scopeTotals(clone $base),
            'buckets' => $buckets,
            'ranking' => $ranking,
        ];
    }

    // ========== Query base ==========

    /**
     * Query ฐาน: รายการผู้เบิกทุกแถวของเงื่อนไขที่เลือก
     * เชื่อมสังกัดผ่านตำแหน่งปัจจุบันของบุคลากร (employees.duty/group/work)
     * พร้อม fallback: มีแต่กลุ่มงาน → หาภารกิจจากกลุ่ม, มีแต่งาน → หากลุ่มงาน/ภารกิจจากงาน
     */
    protected function baseQuery(int $fiscalYear, array $statuses, array $filters): Builder
    {
        $query = DB::table('travel_expense_claim_items as items')
            ->join('travel_expense_claims as claims', 'items.travel_expense_claim_id', '=', 'claims.id')
            ->leftJoin('employees as e', 'items.employee_id', '=', 'e.id')
            // สังกัดของบุคลากร + อนุมานจากกลุ่มงาน/งานเมื่อไม่ผูกภารกิจไว้
            ->leftJoin('duties as d1', 'e.duty_id', '=', 'd1.id')
            ->leftJoin('groups as g1', 'e.group_id', '=', 'g1.id')
            ->leftJoin('duties as d2', 'g1.duty_id', '=', 'd2.id')
            ->leftJoin('works as ew', 'e.work_id', '=', 'ew.id')
            ->leftJoin('groups as g2', 'ew.group_id', '=', 'g2.id')
            ->leftJoin('duties as d3', 'g2.duty_id', '=', 'd3.id')
            ->where('claims.fiscal_year', $fiscalYear)
            ->whereIn('claims.status', $statuses)
            // ใช้ when(ค่า, fn) ตรง ๆ เพื่อให้ callback ได้ค่าจริง — ไม่ใช่ !empty() ที่ให้ bool
            ->when($filters['period_from'] ?? null, fn ($q, $from) => $q->where(
                'claims.claim_period',
                '>=',
                CarbonImmutable::parse($from)->startOfMonth()->toDateString()
            ))
            ->when($filters['period_to'] ?? null, fn ($q, $to) => $q->where(
                'claims.claim_period',
                '<=',
                CarbonImmutable::parse($to)->endOfMonth()->toDateString()
            ))
            // ตัวกรองสังกัด — สัมพันธ์กับระดับที่ดูอยู่
            ->when($filters['duty_id'] ?? null, fn ($q, $dutyId) => $q->whereRaw(
                'COALESCE(d1.id, d2.id, d3.id) = ?',
                [(int) $dutyId]
            ))
            ->when($filters['group_id'] ?? null, fn ($q, $groupId) => $q->whereRaw(
                'COALESCE(g1.id, g2.id) = ?',
                [(int) $groupId]
            ))
            ->when($filters['work_id'] ?? null, fn ($q, $workId) => $q->where(
                'e.work_id',
                (int) $workId
            ));

        return $query;
    }

    /**
     * ต่อข้อความแบบ cross-DB — MySQL ใช้ CONCAT() แต่ SQLite (ชุดทดสอบ) ไม่มี
     * จึงใช้ตัวดำเนินการ || สำหรับ sqlite แทน
     */
    protected function concatSql(array $parts): string
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return implode(' || ', $parts);
        }

        return 'CONCAT(' . implode(', ', $parts) . ')';
    }

    /** SQL ของรหัสบุคคล — รวมคนด้วยรหัส ไม่ใช่ชื่อ (คืน NULL เมื่อไม่มีรหัสใดเลย) */
    protected function personKeySql(): string
    {
        return 'CASE WHEN items.employee_id IS NOT NULL THEN ' . $this->concatSql(["'e:'", 'items.employee_id']) . ' '
            . "WHEN items.pid IS NOT NULL AND items.pid <> '' THEN " . $this->concatSql(["'p:'", 'items.pid']) . ' END';
    }

    /** รหัสบุคลากรที่ใช้แสดงผล (pid บนรายการ หรือรหัสของบุคลากรที่ผูกไว้) */
    protected function personPidSql(): string
    {
        return "MAX(COALESCE(NULLIF(items.pid, ''), e.employee_id))";
    }

    /** ชื่อที่บันทึกไว้บนรายการ (snapshot ณ เวลาที่บันทึก) */
    protected function personNameSql(): string
    {
        return 'MAX(TRIM(' . $this->concatSql(['items.first_name', "' '", 'items.last_name']) . '))';
    }

    // ========== Aggregations ==========

    /** ยอดรวมของขอบเขตที่กำลังดู */
    protected function scopeTotals(Builder $query): array
    {
        $row = $query->selectRaw('
                COALESCE(SUM(items.total_amount), 0) as total_amount,
                COUNT(DISTINCT claims.id) as claim_count,
                COUNT(DISTINCT ' . $this->personKeySql() . ') as claimant_count
            ')
            ->first();

        return [
            'total_amount'   => round((float) ($row->total_amount ?? 0), 2),
            'claim_count'    => (int) ($row->claim_count ?? 0),
            'claimant_count' => (int) ($row->claimant_count ?? 0),
        ];
    }

    /**
     * หมวดย่อยของระดับที่โฟกัส (กลุ่มงานของภารกิจ หรือ งานของกลุ่มงาน)
     * แต่ละหมวดมี top spenders ของหมวดนั้น (เสมอกันแสดงทุกคน) พร้อมสัดส่วน
     */
    protected function buckets(Builder $query, string $level): array
    {
        // หน้าจอแต่ละระดับ: level=duty → แถวคือภารกิจ, level=group → แถวคือกลุ่มงาน
        // (ขอบเขตด้วย duty_id), level=work → แถวคืองาน (ขอบเขตด้วย group_id)
        [$bucketIdSql, $bucketNameSql] = $level === self::LEVEL_DUTY
            ? [
                'COALESCE(d1.id, d2.id, d3.id)',
                "COALESCE(d1.name, d2.name, d3.name, '".self::UNASSIGNED_LABEL."')",
            ]
            : ($level === self::LEVEL_GROUP
                ? [
                    'COALESCE(g1.id, g2.id)',
                    "COALESCE(g1.name, g2.name, '".self::UNASSIGNED_LABEL."')",
                ]
                : [
                    'ew.id',
                    "COALESCE(ew.name, '".self::UNASSIGNED_LABEL."')",
                ]);

        // clone ไว้ก่อน query แรก เพราะ selectRaw ต่อ select ใหม่ทับ select เดิมไม่ได้
        $personsQuery = clone $query;

        // ยอดรวม/จำนวนใบ/จำนวนผู้เบิก ต่อหมวด
        $aggregates = $query
            ->selectRaw("
                {$bucketIdSql} as bucket_id,
                {$bucketNameSql} as bucket_name,
                COALESCE(SUM(items.total_amount), 0) as total_amount,
                COUNT(DISTINCT claims.id) as claim_count,
                COUNT(DISTINCT {$this->personKeySql()}) as claimant_count
            ")
            ->groupBy('bucket_id', 'bucket_name')
            ->orderByDesc('total_amount')
            ->orderBy('bucket_name')
            ->get()
            ->keyBy(fn ($row) => $row->bucket_id === null ? 'null' : (string) $row->bucket_id);

        if ($aggregates->isEmpty()) {
            return [];
        }

        // ยอดต่อคนในแต่ละหมวด — ไว้หาผู้เบิกสูงสุดของแต่ละหมวด
        $persons = $personsQuery
            ->whereNotNull(DB::raw($this->personKeySql()))
            ->selectRaw("
                {$bucketIdSql} as bucket_id,
                {$this->personKeySql()} as person_key,
                {$this->personPidSql()} as pid,
                {$this->personNameSql()} as name,
                COALESCE(SUM(items.total_amount), 0) as total_amount,
                COUNT(DISTINCT claims.id) as claim_count
            ")
            ->groupBy('bucket_id', 'person_key')
            ->get()
            ->groupBy(fn ($row) => $row->bucket_id === null ? 'null' : (string) $row->bucket_id);

        return $aggregates
            ->map(function ($row) use ($persons) {
                $bucketTotal = (float) $row->total_amount;
                $bucketPersons = $persons->get($row->bucket_id === null ? 'null' : (string) $row->bucket_id, collect());

                $max = (float) $bucketPersons->max('total_amount');

                // ผู้เบิกสูงสุด = ยอดเงินมากที่สุด (ไม่ใช่จำนวนใบ) — เสมอกันแสดงทุกคน
                $topSpenders = $bucketPersons
                    ->filter(fn ($p) => (float) $p->total_amount === $max && $max > 0)
                    ->sortByDesc(fn ($p) => [(float) $p->total_amount, (int) $p->claim_count])
                    ->values()
                    ->map(fn ($p) => $this->presentPerson($p, $bucketTotal))
                    ->all();

                return [
                    'id'             => $row->bucket_id === null ? null : (int) $row->bucket_id,
                    'name'           => $row->bucket_name,
                    'total_amount'   => round($bucketTotal, 2),
                    'claim_count'    => (int) $row->claim_count,
                    'claimant_count' => (int) $row->claimant_count,
                    'top_spenders'   => $topSpenders,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * ตารางจัดอันดับบุคคลตามยอดเงินเบิกรวม (มาก → น้อย) ภายใต้ขอบเขตที่กำลังดู
     * รายการที่ไม่มีรหัสบุคคลเลย (employee_id และ pid ว่าง) ยังนับยอดใน totals
     * แต่ระบุตัวบุคคลไม่ได้ จึงไม่ปรากฏในอันดับ
     */
    protected function personRanking(Builder $query): array
    {
        $rows = $query
            ->whereNotNull(DB::raw($this->personKeySql()))
            ->selectRaw("
                {$this->personKeySql()} as person_key,
                {$this->personPidSql()} as pid,
                {$this->personNameSql()} as name,
                COALESCE(SUM(items.total_amount), 0) as total_amount,
                COUNT(DISTINCT claims.id) as claim_count
            ")
            ->groupBy('person_key')
            ->orderByDesc('total_amount')
            ->orderBy('name')
            ->get();

        $scopeTotal = (float) $rows->sum('total_amount');

        return $rows
            ->values()
            ->map(fn ($row, $index) => array_merge(
                ['rank' => $index + 1],
                $this->presentPerson($row, $scopeTotal)
            ))
            ->all();
    }

    // ========== Presenters ==========

    protected function presentPerson(object $row, float $scopeTotal): array
    {
        $total = round((float) $row->total_amount, 2);

        return [
            'person_key'   => $row->person_key,
            'pid'          => $row->pid,
            'name'         => $row->name,
            'claim_count'  => (int) $row->claim_count,
            'total_amount' => $total,
            // สัดส่วนเทียบยอดรวมของภารกิจ/กลุ่มงาน/งานที่กำลังดู (%)
            'share'        => $scopeTotal > 0 ? round($total / $scopeTotal * 100, 2) : 0.0,
        ];
    }
}
