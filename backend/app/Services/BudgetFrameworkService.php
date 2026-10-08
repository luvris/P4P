<?php

namespace App\Services;

use App\Models\BudgetFramework;
use App\Models\EmployeeStatus;
use App\Models\ProfessionGroupMapping;
use App\Support\ProfessionalGroupCatalog;
use App\Support\ThaiFiscalYear;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * คำนวณ "กรอบวงเงิน P4P" ของปีงบประมาณหนึ่ง ๆ จากข้อมูลที่มีอยู่แล้ว
 *
 * - ค่าแรง  : ผลรวมของ payrolls เฉพาะบุคลากรที่ยังปฏิบัติงานอยู่ (ฐานเดียวกับเงินสำรอง)
 * - จำนวนคน : ทะเบียน employees ที่สถานะ "ปฏิบัติงานอยู่" จัดกลุ่มตามชื่อตำแหน่ง
 * - การเฉลี่ยวงเงิน Activity : แบ่งตาม "สัดส่วนถ่วงน้ำหนัก" ของแต่ละกลุ่มวิชาชีพ
 *
 * ฟีเจอร์ใหม่ทั้งหมด — ไม่เรียกใช้/แก้ไขโค้ดของ ReserveFundController
 */
class BudgetFrameworkService
{
    /** ฐานค่าแรงที่รองรับ (คอลัมน์ใน payrolls) */
    public const BASIS_TOTAL_INCOME = 'total_income';

    public const BASIS_SALARY = 'salary';

    public const COST_BASES = [
        self::BASIS_TOTAL_INCOME => 'ยอดรวมรายรับทั้งหมด รายบุคคล',
        self::BASIS_SALARY => 'เงินเดือน',
    ];

    /** @return array<string, mixed> ค่าเริ่มต้นของพารามิเตอร์ */
    public function defaultParams(): array
    {
        $weights = [];

        foreach (ProfessionalGroupCatalog::definitions() as $code => $definition) {
            $weights[$code] = $definition['default_weight'];
        }

        return [
            'cost_basis' => self::BASIS_TOTAL_INCOME,
            'labor_percent' => 3.0,
            'activity_ratio' => 70.0,
            'quality_ratio' => 30.0,
            'as_of_month' => null,
            'weights' => $weights,
        ];
    }

    /**
     * รวมพารามิเตอร์ที่ส่งมากับค่าเริ่มต้น และตรวจฐานค่าแรงให้อยู่ในรายการที่รองรับ
     */
    public function normalizeParams(array $params): array
    {
        $defaults = $this->defaultParams();

        $basis = $params['cost_basis'] ?? $defaults['cost_basis'];
        if (! array_key_exists($basis, self::COST_BASES)) {
            $basis = $defaults['cost_basis'];
        }

        $weights = $defaults['weights'];
        foreach (($params['weights'] ?? []) as $code => $value) {
            if (array_key_exists($code, $weights) && is_numeric($value)) {
                $weights[$code] = (float) $value;
            }
        }

        return [
            'cost_basis' => $basis,
            'labor_percent' => (float) ($params['labor_percent'] ?? $defaults['labor_percent']),
            'activity_ratio' => (float) ($params['activity_ratio'] ?? $defaults['activity_ratio']),
            'quality_ratio' => (float) ($params['quality_ratio'] ?? $defaults['quality_ratio']),
            'as_of_month' => isset($params['as_of_month']) && $params['as_of_month'] !== ''
                ? (int) $params['as_of_month']
                : null,
            'weights' => $weights,
        ];
    }

    /**
     * สร้าง payload ทั้งหมดของกรอบวงเงิน
     *
     * @param  array<string, mixed>  $params  ค่าที่ normalize แล้ว
     * @return array<string, mixed>
     */
    public function build(int $fiscalYear, array $params): array
    {
        $params = $this->normalizeParams($params);

        $labor = $this->laborCost($fiscalYear, $params['cost_basis'], $params['as_of_month']);

        $annual = $labor['monthly'] * 12;
        $toDate = $this->sumToDate($labor['months'], $labor['as_of_month']);

        $p4pAnnual = round($annual * $params['labor_percent'] / 100, 2);
        $activityBudget = round($p4pAnnual * $params['activity_ratio'] / 100, 2);
        $qualityBudget = round($p4pAnnual * $params['quality_ratio'] / 100, 2);

        $groups = $this->groupRows($params['weights'], (float) $activityBudget, $fiscalYear);

        return [
            'fiscal_year' => $fiscalYear,
            'cost_basis' => $params['cost_basis'],
            'cost_basis_label' => self::COST_BASES[$params['cost_basis']],
            'months_present' => $labor['months_present'],
            'as_of_month' => $labor['as_of_month'],
            'as_of_label' => $labor['as_of_label'],
            'months' => $labor['months'],
            'labor_cost' => [
                'monthly' => round($labor['monthly'], 2),
                'annual' => round($annual, 2),
                'to_date' => round($toDate, 2),
            ],
            'labor_percent' => $params['labor_percent'],
            'activity_ratio' => $params['activity_ratio'],
            'quality_ratio' => $params['quality_ratio'],
            'p4p_annual' => $p4pAnnual,
            'activity_budget' => $activityBudget,
            'quality_budget' => $qualityBudget,
            'unit_rate_year' => $groups['unit_rate_year'],
            'unit_rate_month' => $groups['unit_rate_month'],
            'groups' => $groups['rows'],
            'total' => $groups['total'],
        ];
    }

    /**
     * ยอดค่าแรงรายเดือนของปีงบประมาณ
     *
     * @return array<string, mixed>
     */
    protected function laborCost(int $fiscalYear, string $basis, ?int $asOfMonth): array
    {
        $basis = array_key_exists($basis, self::COST_BASES) ? $basis : self::BASIS_TOTAL_INCOME;

        // งวดที่มีข้อมูล + ชุดข้อมูลล่าสุดของแต่ละงวด (เหมือนเงินสำรอง — งวดซ้ำใช้ชุดที่อัปโหลดล่าสุด)
        $periods = $this->payrollPeriods($fiscalYear);

        $months = [];
        $sum = 0.0;
        $lastMonth = null;

        foreach (ThaiFiscalYear::MONTHS as $month) {
            $period = $periods->get($month);

            if ($period === null) {
                $months[] = [
                    'period_month' => $month,
                    'period_year' => ThaiFiscalYear::calendarYearOf($fiscalYear, $month),
                    'period_label' => ThaiFiscalYear::monthLabel($month),
                    'has_data' => false,
                    'amount' => 0.0,
                ];

                continue;
            }

            $amount = (float) $this->activePayrollQuery()
                ->whereRaw('COALESCE(p.fiscal_year, i.fiscal_year) = ?', [$fiscalYear])
                ->whereRaw('COALESCE(p.period_month, i.period_month) = ?', [$month])
                ->where('p.import_id', $period['import_id'])
                ->sum(DB::raw("COALESCE(p.{$basis}, 0)"));

            $months[] = [
                'period_month' => $month,
                'period_year' => ThaiFiscalYear::calendarYearOf($fiscalYear, $month),
                'period_label' => ThaiFiscalYear::monthLabel($month),
                'has_data' => true,
                'amount' => round($amount, 2),
            ];

            $sum += $amount;
            $lastMonth = $month;
        }

        $monthsPresent = count(array_filter($months, fn ($m) => $m['has_data']));

        // งวดล่าสุดที่มีข้อมูล (หรือค่าที่ผู้ใช้ระบุ ถ้ามีข้อมูลจริงในงวดนั้น)
        $hasData = function (?int $month) use ($months): bool {
            if ($month === null) {
                return false;
            }

            $match = collect($months)->firstWhere('period_month', $month);

            return (bool) ($match['has_data'] ?? false);
        };

        $asOf = $hasData($asOfMonth) ? $asOfMonth : $lastMonth;

        return [
            'months' => $months,
            'months_present' => $monthsPresent,
            'monthly' => $monthsPresent > 0 ? $sum / $monthsPresent : 0.0,
            'as_of_month' => $asOf,
            'as_of_label' => $this->asOfLabel($fiscalYear, $asOf),
        ];
    }

    /**
     * ป้ายวันที่ของงวดล่าสุด เช่น "31 กรกฎาคม 2568"
     */
    protected function asOfLabel(int $fiscalYear, ?int $month): ?string
    {
        if ($month === null) {
            return null;
        }

        $calendarYear = ThaiFiscalYear::calendarYearOf($fiscalYear, $month);
        $date = CarbonImmutable::create($calendarYear - 543, $month, 1)->endOfMonth();

        return $date->day.' '.ThaiFiscalYear::monthLabel($month).' '.$calendarYear;
    }

    /**
     * ผลรวมค่าแรงสะสมถึงงวดที่เลือก
     *
     * @param  array<int, array<string, mixed>>  $months
     */
    protected function sumToDate(array $months, ?int $asOfMonth): float
    {
        $sum = 0.0;

        foreach ($months as $month) {
            if (! $month['has_data']) {
                continue;
            }

            $sum += $month['amount'];

            if ($asOfMonth !== null && $month['period_month'] === $asOfMonth) {
                break;
            }
        }

        return $sum;
    }

    /**
     * ตารางกลุ่มวิชาชีพ + ยอดเงินที่เฉลี่ยตามสัดส่วน
     *
     * สูตร:
     *   รวมสัดส่วนถ่วงน้ำหนัก = จำนวนคน × สัดส่วน ของแต่ละกลุ่ม
     *   เงินต่อหน่วย/ปี        = วงเงิน Activity ÷ ผลรวมสัดส่วนถ่วงน้ำหนัก
     *   เงินของกลุ่ม/ปี        = เงินต่อหน่วย/ปี × สัดส่วนถ่วงน้ำหนักของกลุ่ม
     *
     * @param  array<string, float>  $weights
     * @return array<string, mixed>
     */
    protected function groupRows(array $weights, float $activityBudget, int $fiscalYear): array
    {
        $counts = $this->headcountsFromPayroll($fiscalYear);

        $definitions = ProfessionalGroupCatalog::definitions();

        $rows = [];
        foreach ($definitions as $code => $definition) {
            // กลุ่มที่จับคู่ไม่ได้ แสดงเฉพาะเมื่อมีคนจริง
            if ($code === ProfessionalGroupCatalog::UNCLASSIFIED && ($counts[$code]['headcount'] ?? 0) === 0) {
                continue;
            }

            $headcount = $counts[$code]['headcount'] ?? 0;
            $weight = $weights[$code] ?? $definition['default_weight'];

            $rows[] = [
                'code' => $code,
                'name' => $definition['name'],
                'short_name' => $definition['short_name'],
                'examples' => $definition['examples'],
                'headcount' => $headcount,
                'weight' => round($weight, 2),
                'weighted' => round($headcount * $weight, 2),
                'positions' => $counts[$code]['positions'] ?? [],
            ];
        }

        $totalWeighted = array_sum(array_column($rows, 'weighted'));
        $unitRateYear = $totalWeighted > 0 ? $activityBudget / $totalWeighted : 0.0;

        foreach ($rows as &$row) {
            $amountYear = round($unitRateYear * $row['weighted'], 2);
            $amountMonth = round($amountYear / 12, 2);

            $row['amount_year'] = $amountYear;
            $row['amount_month'] = $amountMonth;
            $row['avg_year'] = $row['headcount'] > 0 ? round($amountYear / $row['headcount'], 2) : 0.0;
            $row['avg_month'] = round($row['avg_year'] / 12, 2);
        }
        unset($row);

        $totalAmountYear = round(array_sum(array_column($rows, 'amount_year')), 2);
        $totalAvgYear = round(array_sum(array_column($rows, 'avg_year')), 2);

        return [
            'rows' => $rows,
            'unit_rate_year' => round($unitRateYear, 4),
            'unit_rate_month' => round($unitRateYear / 12, 4),
            'total' => [
                'headcount' => (int) array_sum(array_column($rows, 'headcount')),
                'weight' => round(array_sum(array_column($rows, 'weight')), 2),
                'weighted' => round($totalWeighted, 2),
                'amount_year' => $totalAmountYear,
                'amount_month' => round($totalAmountYear / 12, 2),
                'avg_year' => $totalAvgYear,
                'avg_month' => round($totalAvgYear / 12, 2),
            ],
        ];
    }

    /**
     * ฐาน query ของ payrolls เฉพาะแถวที่ผูกทะเบียนได้และเจ้าตัวยังปฏิบัติงานอยู่
     */
    protected function activePayrollQuery()
    {
        return DB::table('payrolls as p')
            ->leftJoin('imports as i', 'i.id', '=', 'p.import_id')
            ->leftJoin('employees as e', 'e.citizen_id', '=', 'p.citizen_id')
            ->leftJoin('employee_statuses as es', 'es.id', '=', 'e.status_id')
            ->whereNotNull('p.citizen_id')
            ->where('p.citizen_id', '!=', '')
            ->whereNull('e.deleted_at')
            ->whereIn('es.name', [EmployeeStatus::ACTIVE_NAME]);
    }

    /**
     * งวดของปีงบที่มีข้อมูล payroll เรียงตามรอบปีงบ (ต.ค. → ก.ย.)
     * งวดซ้ำใช้ชุดข้อมูลที่อัปโหลดล่าสุดของงวดนั้น
     *
     * @return \Illuminate\Support\Collection<int, array{period_month:int, period_year:int, import_id:int}>
     */
    protected function payrollPeriods(int $fiscalYear)
    {
        $rows = $this->activePayrollQuery()
            ->whereRaw('COALESCE(p.fiscal_year, i.fiscal_year) = ?', [$fiscalYear])
            ->whereNotNull(DB::raw('COALESCE(p.period_month, i.period_month)'))
            ->groupBy(DB::raw('COALESCE(p.period_month, i.period_month)'))
            ->get([
                DB::raw('COALESCE(p.period_month, i.period_month) as period_month'),
                DB::raw('MAX(p.import_id) as import_id'),
            ])
            ->keyBy('period_month');

        $periods = [];

        foreach (ThaiFiscalYear::MONTHS as $month) {
            if (! $rows->has($month)) {
                continue;
            }

            $periods[$month] = [
                'period_month' => (int) $month,
                'period_year' => ThaiFiscalYear::calendarYearOf($fiscalYear, (int) $month),
                'import_id' => (int) $rows->get($month)->import_id,
            ];
        }

        return collect($periods);
    }

    /**
     * รายชื่อตำแหน่งทั้งหมดที่พบใน "ไฟล์เงินเดือน" ของปีงบ (งวดล่าสุด) + กลุ่มวิชาชีพ
     *
     * นับคนไม่ซ้ำต่อตำแหน่ง และจับกลุ่มด้วยตาราง profession_group_mappings ก่อน
     * ถ้าตำแหน่งนั้นยังไม่ถูกกำหนด จึงใช้กฎคำสำคัญใน ProfessionalGroupCatalog
     *
     * @return array{period_month:int|null, period_label:string|null, positions:array<int, array{position_name:string, headcount:int, group_code:string|null, is_overridden:bool}>}
     */
    public function positionsForFiscalYear(int $fiscalYear): array
    {
        $periods = $this->payrollPeriods($fiscalYear);

        if ($periods->isEmpty()) {
            return ['period_month' => null, 'period_label' => null, 'positions' => []];
        }

        /** @var array{period_month:int, period_year:int, import_id:int} $latest */
        $latest = $periods->last();

        $rows = $this->activePayrollQuery()
            ->whereRaw('COALESCE(p.fiscal_year, i.fiscal_year) = ?', [$fiscalYear])
            ->whereRaw('COALESCE(p.period_month, i.period_month) = ?', [$latest['period_month']])
            ->where('p.import_id', $latest['import_id'])
            ->whereNotNull('p.position_name')
            ->where('p.position_name', '!=', '')
            ->distinct()
            ->get(['p.position_name', 'p.citizen_id']);

        $overrides = ProfessionGroupMapping::codeMap();
        $byPosition = [];

        foreach ($rows as $row) {
            $name = trim((string) $row->position_name);

            if ($name === '') {
                continue;
            }

            $byPosition[$name] = ($byPosition[$name] ?? 0) + 1;
        }

        $positions = [];

        foreach ($byPosition as $name => $count) {
            $isOverridden = array_key_exists($name, $overrides);

            $positions[] = [
                'position_name' => $name,
                'headcount' => $count,
                'group_code' => $isOverridden
                    ? $overrides[$name]
                    : ProfessionalGroupCatalog::classify($name),
                'is_overridden' => $isOverridden,
            ];
        }

        usort($positions, function ($a, $b) {
            return ($b['headcount'] <=> $a['headcount'])
                ?: strcmp($a['position_name'], $b['position_name']);
        });

        return [
            'period_month' => $latest['period_month'],
            'period_label' => ThaiFiscalYear::monthLabel((int) $latest['period_month'])
                . ' ' . $latest['period_year'],
            'positions' => $positions,
        ];
    }

    /**
     * นับจำนวนคนต่อกลุ่มวิชาชีพจากไฟล์เงินเดือน (งวดล่าสุด)
     *
     * @return array<string, array{headcount:int, positions:array<int, array{name:string, count:int}>}>
     */
    protected function headcountsFromPayroll(int $fiscalYear): array
    {
        $counts = [];

        foreach ($this->positionsForFiscalYear($fiscalYear)['positions'] as $position) {
            $code = $position['group_code'] ?? ProfessionalGroupCatalog::UNCLASSIFIED;
            $label = $position['position_name'];
            $count = $position['headcount'];

            $counts[$code]['headcount'] = ($counts[$code]['headcount'] ?? 0) + $count;
            $counts[$code]['positions'][$label] = ($counts[$code]['positions'][$label] ?? 0) + $count;
        }

        // เรียงตำแหน่งในกลุ่มตามจำนวนคน (มาก → น้อย)
        foreach ($counts as &$group) {
            $positions = $group['positions'] ?? [];
            arsort($positions);

            $group['positions'] = collect($positions)
                ->map(fn ($count, $name) => ['name' => (string) $name, 'count' => (int) $count])
                ->values()
                ->all();
        }
        unset($group);

        return $counts;
    }

    /**
     * แปลงกรอบวงเงินที่บันทึกไว้ให้เป็น payload รูปแบบเดียวกับ build()
     *
     * @return array<string, mixed>
     */
    public function snapshot(BudgetFramework $framework): array
    {
        return [
            'id' => $framework->id,
            'fiscal_year' => $framework->fiscal_year,
            'cost_basis' => $framework->cost_basis,
            'cost_basis_label' => self::COST_BASES[$framework->cost_basis] ?? $framework->cost_basis,
            'months_present' => $framework->months_present,
            'as_of_month' => $framework->as_of_month,
            'as_of_label' => $this->asOfLabel((int) $framework->fiscal_year, $framework->as_of_month),
            'months' => [],
            'labor_cost' => [
                'monthly' => (float) $framework->labor_cost_monthly,
                'annual' => (float) $framework->labor_cost_annual,
                'to_date' => (float) $framework->labor_cost_to_date,
            ],
            'labor_percent' => (float) $framework->labor_percent,
            'activity_ratio' => (float) $framework->activity_ratio,
            'quality_ratio' => (float) $framework->quality_ratio,
            'p4p_annual' => (float) $framework->p4p_annual,
            'activity_budget' => (float) $framework->activity_budget,
            'quality_budget' => (float) $framework->quality_budget,
            'unit_rate_year' => (float) $framework->unit_rate_year,
            'unit_rate_month' => (float) $framework->unit_rate_month,
            'groups' => $framework->groups ?? [],
            'total' => [
                'headcount' => $framework->total_headcount,
                'weight' => (float) $framework->total_weight,
                'weighted' => (float) $framework->total_weighted,
                'amount_year' => (float) $framework->activity_budget,
                'amount_month' => round((float) $framework->activity_budget / 12, 2),
                'avg_year' => round(array_sum(array_map(fn ($g) => (float) ($g['avg_year'] ?? 0), $framework->groups ?? [])), 2),
                'avg_month' => round(array_sum(array_map(fn ($g) => (float) ($g['avg_month'] ?? 0), $framework->groups ?? [])), 2),
            ],
        ];
    }

    /**
     * ข้อมูลที่ต้องบันทึกลงตาราง จาก payload ที่คำนวณได้
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function toAttributes(array $payload): array
    {
        return [
            'cost_basis' => $payload['cost_basis'],
            'as_of_month' => $payload['as_of_month'],
            'months_present' => $payload['months_present'],
            'labor_cost_monthly' => $payload['labor_cost']['monthly'],
            'labor_cost_annual' => $payload['labor_cost']['annual'],
            'labor_cost_to_date' => $payload['labor_cost']['to_date'],
            'labor_percent' => $payload['labor_percent'],
            'activity_ratio' => $payload['activity_ratio'],
            'quality_ratio' => $payload['quality_ratio'],
            'p4p_annual' => $payload['p4p_annual'],
            'activity_budget' => $payload['activity_budget'],
            'quality_budget' => $payload['quality_budget'],
            'total_headcount' => $payload['total']['headcount'],
            'total_weight' => $payload['total']['weight'],
            'total_weighted' => $payload['total']['weighted'],
            'unit_rate_year' => $payload['unit_rate_year'],
            'unit_rate_month' => $payload['unit_rate_month'],
            'groups' => $payload['groups'],
        ];
    }
}
