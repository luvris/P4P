<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BudgetFramework;
use App\Models\ProfessionGroupMapping;
use App\Services\BudgetFrameworkExporter;
use App\Services\BudgetFrameworkService;
use App\Support\ProfessionalGroupCatalog;
use App\Support\ThaiFiscalYear;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * กรอบวงเงิน P4P — คำนวณ / บันทึก / ส่งออกเอกสาร Excel
 *
 * ทุก endpoint คำนวณจากข้อมูลที่มีอยู่แล้วในระบบ (payrolls + employees/positions)
 * โดยผู้ใช้กำหนด ร้อยละของค่าแรง, สัดส่วน Activity/Quality และสัดส่วนต่อกลุ่มวิชาชีพ
 *
 * ฟีเจอร์ใหม่ทั้งหมด — ไม่แก้ไขคอนโทรลเลอร์/บริการเดิม
 */
class BudgetFrameworkController extends Controller
{
    public function __construct(
        protected BudgetFrameworkService $service,
    ) {}

    /**
     * กติกาการกรอกข้อมูลร่วมของ preview / store / export
     */
    protected function rules(): array
    {
        return [
            'fiscal_year' => ['nullable', 'integer', 'min:'.ThaiFiscalYear::MIN, 'max:'.ThaiFiscalYear::MAX],
            'cost_basis' => ['nullable', 'in:total_income,salary'],
            'labor_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'activity_ratio' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'quality_ratio' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'as_of_month' => ['nullable', 'integer', 'min:1', 'max:12'],
            'weights' => ['nullable', 'array'],
            'weights.*' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    protected function fiscalYear(array $validated): int
    {
        return (int) ($validated['fiscal_year'] ?? ThaiFiscalYear::current());
    }

    /**
     * GET /api/finance/budget-frameworks/preview
     * คำนวณกรอบวงเงินสด ๆ (ยังไม่บันทึก)
     */
    public function preview(Request $request): JsonResponse
    {
        $validated = $request->validate($this->rules());

        $fiscalYear = $this->fiscalYear($validated);
        $payload = $this->service->build($fiscalYear, $validated);

        return response()->json([
            'data' => $payload,
            'meta' => $this->meta($fiscalYear, $payload),
        ]);
    }

    /**
     * GET /api/finance/budget-frameworks
     * รายการกรอบวงเงินที่บันทึกไว้
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'fiscal_year' => ['nullable', 'integer', 'min:'.ThaiFiscalYear::MIN, 'max:'.ThaiFiscalYear::MAX],
        ]);

        $frameworks = BudgetFramework::query()
            ->with('creator:id,name')
            ->when(
                $validated['fiscal_year'] ?? null,
                fn ($query, $year) => $query->where('fiscal_year', $year)
            )
            ->orderByDesc('fiscal_year')
            ->get()
            ->map(fn (BudgetFramework $framework) => $this->present($framework));

        return response()->json(['data' => $frameworks]);
    }

    /**
     * POST /api/finance/budget-frameworks
     * บันทึกกรอบวงเงินของปีงบประมาณ (บันทึกซ้ำ = อัปเดตแถวเดิม)
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->rules());

        $fiscalYear = $this->fiscalYear($validated);
        $payload = $this->service->build($fiscalYear, $validated);
        $userId = $request->user()?->id;

        // ไม่มีบุคลากรในระบบเลย = กรอบว่าง บันทึกไม่มีความหมาย
        if ($payload['total']['headcount'] === 0) {
            return response()->json([
                'message' => "ยังไม่มีข้อมูลบุคลากรที่ปฏิบัติงานอยู่ของปีงบประมาณ {$fiscalYear} จึงยังบันทึกไม่ได้",
            ], 422);
        }

        $framework = BudgetFramework::updateOrCreate(
            ['fiscal_year' => $fiscalYear],
            array_merge($this->service->toAttributes($payload), [
                'note' => $validated['note'] ?? null,
                'updated_by' => $userId,
            ])
        );

        if ($framework->wasRecentlyCreated) {
            $framework->forceFill(['created_by' => $userId])->save();
        }

        return response()->json([
            'message' => "บันทึกกรอบวงเงิน P4P ปีงบประมาณ {$fiscalYear} สำเร็จ",
            'data' => $this->present($framework->fresh()),
        ], $framework->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * GET /api/finance/budget-frameworks/export
     * ส่งออก Excel จากค่าที่ส่งมา (ตรงกับที่แสดงบนหน้าจอ)
     */
    public function export(Request $request, BudgetFrameworkExporter $exporter): BinaryFileResponse
    {
        $validated = $request->validate($this->rules());

        $fiscalYear = $this->fiscalYear($validated);
        $payload = $this->service->build($fiscalYear, $validated);

        return $exporter->download($payload);
    }

    /**
     * GET /api/finance/budget-frameworks/{framework}/export
     * ส่งออก Excel จากกรอบวงเงินที่บันทึกไว้ (ตัวเลขตรงกับตอนบันทึก)
     */
    public function exportSaved(BudgetFramework $framework, BudgetFrameworkExporter $exporter): BinaryFileResponse
    {
        return $exporter->download($this->service->snapshot($framework));
    }    /**
     * GET /api/finance/budget-frameworks/options
     * ค่าเริ่มต้น + นิยามกลุ่มวิชาชีพ สำหรับหน้าจอกรอกข้อมูล
     */
    public function options(): JsonResponse
    {
        return response()->json([
            'data' => [
                'groups'      => $this->groupCatalog(),
                'cost_bases'  => array_map(
                    fn ($value, $label) => ['value' => $value, 'label' => $label],
                    array_keys(BudgetFrameworkService::COST_BASES),
                    array_values(BudgetFrameworkService::COST_BASES),
                ),
                'defaults'    => $this->service->defaultParams(),
                'fiscal_year' => ThaiFiscalYear::current(),
            ],
        ]);
    }

    /**
     * GET /api/finance/budget-frameworks/position-groups
     * รายชื่อตำแหน่งจากไฟล์เงินเดือน + กลุ่มวิชาชีพที่ถูกจับไว้ (สำหรับหน้าจอแก้ mapping)
     */
    public function positionGroups(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'fiscal_year' => ['nullable', 'integer', 'min:' . ThaiFiscalYear::MIN, 'max:' . ThaiFiscalYear::MAX],
        ]);

        $fiscalYear = $this->fiscalYear($validated);

        return response()->json(['data' => $this->positionGroupPayload($fiscalYear)]);
    }

    /**
     * PUT /api/finance/budget-frameworks/position-groups
     * บันทึกการจับคู่ ตำแหน่ง → กลุ่มวิชาชีพ (บันทึกทับเฉพาะตำแหน่งที่ส่งมา)
     */
    public function updatePositionGroups(Request $request): JsonResponse
    {
        $allowedCodes = array_merge(
            ProfessionalGroupCatalog::codes(),
            [ProfessionalGroupCatalog::UNCLASSIFIED],
        );

        $validated = $request->validate([
            'fiscal_year' => ['nullable', 'integer', 'min:' . ThaiFiscalYear::MIN, 'max:' . ThaiFiscalYear::MAX],
            'mappings'    => ['required', 'array'],
            'mappings.*'  => ['nullable', 'string', 'in:' . implode(',', $allowedCodes)],
        ], [
            'mappings.required' => 'ไม่มีข้อมูลการจับกลุ่มวิชาชีพที่ต้องบันทึก',
            'mappings.*.in'     => 'กลุ่มวิชาชีพที่เลือกไม่ถูกต้อง',
        ]);

        $fiscalYear = $this->fiscalYear($validated);

        foreach ($validated['mappings'] as $positionName => $code) {
            // ค่าว่าง = ล้างการกำหนดเอง แล้วกลับไปใช้กฎคำสำคัญ
            ProfessionGroupMapping::updateOrCreate(
                ['position_name' => (string) $positionName],
                ['group_code' => $code ?: null],
            );
        }

        return response()->json([
            'message' => 'บันทึกการจับกลุ่มวิชาชีพเรียบร้อย',
            'data'    => $this->positionGroupPayload($fiscalYear),
        ]);
    }

    /**
     * ข้อมูลตำแหน่ง + กลุ่มที่จับไว้ + รายการกลุ่มสำหรับ dropdown
     *
     * @return array<string, mixed>
     */
    protected function positionGroupPayload(int $fiscalYear): array
    {
        return array_merge(
            $this->service->positionsForFiscalYear($fiscalYear),
            [
                'fiscal_year' => $fiscalYear,
                'groups'      => $this->groupCatalog(),
            ],
        );
    }

    /**
     * นิยามกลุ่มวิชาชีพ 9 กลุ่ม (สำหรับ dropdown)
     *
     * @return array<int, array<string, mixed>>
     */
    protected function groupCatalog(): array
    {
        $groups = [];

        foreach (ProfessionalGroupCatalog::groups() as $index => $group) {
            $groups[] = [
                'code'           => $group['code'],
                'name'           => $group['name'],
                'short_name'     => $group['short_name'],
                'examples'       => $group['examples'],
                'default_weight' => $group['default_weight'],
                'sort_order'     => $index + 1,
            ];
        }

        return $groups;
    }

    /**
     * ข้อมูลกรอบวงเงินที่บันทึกไว้ สำหรับหน้ารายการ
     */
    protected function present(BudgetFramework $framework): array
    {
        return [
            'id' => $framework->id,
            'fiscal_year' => $framework->fiscal_year,
            'cost_basis' => $framework->cost_basis,
            'months_present' => $framework->months_present,
            'as_of_month' => $framework->as_of_month,
            'labor_cost_monthly' => (float) $framework->labor_cost_monthly,
            'labor_cost_annual' => (float) $framework->labor_cost_annual,
            'labor_cost_to_date' => (float) $framework->labor_cost_to_date,
            'labor_percent' => (float) $framework->labor_percent,
            'activity_ratio' => (float) $framework->activity_ratio,
            'quality_ratio' => (float) $framework->quality_ratio,
            'p4p_annual' => (float) $framework->p4p_annual,
            'activity_budget' => (float) $framework->activity_budget,
            'quality_budget' => (float) $framework->quality_budget,
            'total_headcount' => $framework->total_headcount,
            'unit_rate_month' => (float) $framework->unit_rate_month,
            'note' => $framework->note,
            'created_by_name' => $framework->creator?->name,
            'saved_at' => $framework->updated_at,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function meta(int $fiscalYear, array $payload): array
    {
        return [
            'fiscal_year' => $fiscalYear,
            'months_present' => $payload['months_present'],
            'months_missing' => array_values(array_map(
                fn ($month) => $month['period_month'],
                array_filter($payload['months'] ?? [], fn ($month) => ! $month['has_data'])
            )),
        ];
    }
}
