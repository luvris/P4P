<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\TravelExpenseClaim;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ใบเบิกค่าใช้จ่ายเดินทางไปราชการ
 */
class TravelExpenseClaimTest extends TestCase
{
    use RefreshDatabase;

    protected User $finance;
    protected Employee $somchai;
    protected Employee $somying;

    protected function setUp(): void
    {
        parent::setUp();

        $this->finance = User::create([
            'name'     => 'Finance Staff',
            'username' => 'finance_tec',
            'email'    => 'finance_tec@example.test',
            'password' => bcrypt('secret-password'),
            'role'     => 'finance',
        ]);

        $this->somchai = Employee::create([
            'citizen_id'  => '1111111111111',
            'employee_id' => 'P001',
            'first_name'  => 'สมชาย',
            'last_name'   => 'ใจดี',
        ]);

        $this->somying = Employee::create([
            'citizen_id'  => '2222222222222',
            'employee_id' => 'P002',
            'first_name'  => 'สมหญิง',
            'last_name'   => 'รักดี',
        ]);
    }

    /** payload ตั้งต้น: มิถุนายน 2569 อยู่ในปีงบประมาณ 2569 */
    protected function payload(array $overrides = []): array
    {
        return array_merge([
            'fiscal_year'      => 2569,
            'claim_period'     => '2026-06-01',
            'expense_category' => TravelExpenseClaim::DEFAULT_CATEGORY,
            'items'            => [
                [
                    'employee_id'           => $this->somchai->id,
                    'pid'                   => 'P001',
                    'first_name'            => 'สมชาย',
                    'last_name'             => 'ใจดี',
                    'allowance_amount'      => 240,
                    'accommodation_amount'  => 800,
                    'transportation_amount' => 120.50,
                    'other_amount'          => 0,
                ],
            ],
        ], $overrides);
    }

    public function test_draft_is_saved_with_document_no_and_backend_total(): void
    {
        $response = $this->actingAs($this->finance)
            ->postJson('/api/finance/travel-expense-claims', $this->payload())
            ->assertCreated();

        $response->assertJsonPath('data.status', 'draft');
        $response->assertJsonPath('data.fiscal_year', 2569);
        $response->assertJsonPath('data.document_no', 'TEC-2569-0001');

        // รวมเงินคำนวณฝั่ง backend: 240 + 800 + 120.50
        $response->assertJsonPath('data.items.0.total_amount', 1160.5);
        $response->assertJsonPath('data.totals.total_amount', 1160.5);
    }

    public function test_frontend_total_amount_is_ignored(): void
    {
        $payload = $this->payload();
        $payload['items'][0]['total_amount'] = 999999;

        $this->actingAs($this->finance)
            ->postJson('/api/finance/travel-expense-claims', $payload)
            ->assertCreated()
            ->assertJsonPath('data.items.0.total_amount', 1160.5);
    }

    public function test_claim_month_must_be_inside_selected_fiscal_year(): void
    {
        // ตุลาคม 2569 อยู่ในปีงบประมาณ 2570 ไม่ใช่ 2569
        $this->actingAs($this->finance)
            ->postJson('/api/finance/travel-expense-claims', $this->payload([
                'claim_period' => '2026-10-01',
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('claim_period');
    }

    public function test_october_belongs_to_next_fiscal_year(): void
    {
        $this->actingAs($this->finance)
            ->postJson('/api/finance/travel-expense-claims', $this->payload([
                'fiscal_year'  => 2570,
                'claim_period' => '2026-10-01',
            ]))
            ->assertCreated()
            ->assertJsonPath('data.document_no', 'TEC-2570-0001');
    }

    public function test_duplicate_employee_in_same_document_is_rejected(): void
    {
        $payload = $this->payload();
        $payload['items'][] = $payload['items'][0];

        $this->actingAs($this->finance)
            ->postJson('/api/finance/travel-expense-claims', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('items');
    }

    public function test_negative_amount_is_rejected(): void
    {
        $payload = $this->payload();
        $payload['items'][0]['allowance_amount'] = -10;

        $this->actingAs($this->finance)
            ->postJson('/api/finance/travel-expense-claims', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('items.0.allowance_amount');
    }

    public function test_confirming_without_items_is_rejected(): void
    {
        $this->actingAs($this->finance)
            ->postJson('/api/finance/travel-expense-claims', $this->payload([
                'status' => 'confirmed',
                'items'  => [],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('items');
    }

    public function test_confirmed_claim_cannot_be_edited(): void
    {
        $claim = $this->createClaim(['status' => 'confirmed']);

        $this->actingAs($this->finance)
            ->putJson("/api/finance/travel-expense-claims/{$claim->id}", $this->payload())
            ->assertStatus(409);
    }

    public function test_cancel_keeps_document_and_blocks_export(): void
    {
        $claim = $this->createClaim(['status' => 'confirmed']);

        $this->actingAs($this->finance)
            ->postJson("/api/finance/travel-expense-claims/{$claim->id}/cancel", [
                'cancel_reason' => 'ยกเลิกการเดินทาง',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        // ไม่ hard delete — หัวเอกสารและรายการย่อยยังอยู่
        $this->assertDatabaseHas('travel_expense_claims', ['id' => $claim->id]);
        $this->assertDatabaseHas('travel_expense_claim_items', [
            'travel_expense_claim_id' => $claim->id,
        ]);

        $this->actingAs($this->finance)
            ->get("/api/finance/travel-expense-claims/{$claim->id}/export")
            ->assertStatus(422);
    }

    public function test_export_requires_confirmed_status(): void
    {
        $claim = $this->createClaim();

        $this->actingAs($this->finance)
            ->get("/api/finance/travel-expense-claims/{$claim->id}/export")
            ->assertStatus(422);

        $this->actingAs($this->finance)
            ->postJson("/api/finance/travel-expense-claims/{$claim->id}/confirm")
            ->assertOk();

        $this->actingAs($this->finance)
            ->get("/api/finance/travel-expense-claims/{$claim->id}/export")
            ->assertOk();
    }

    public function test_list_is_scoped_to_selected_fiscal_year(): void
    {
        $this->createClaim();
        $this->createClaim(['fiscal_year' => 2570, 'claim_period' => '2026-10-01']);

        $this->actingAs($this->finance)
            ->getJson('/api/finance/travel-expense-claims?fiscal_year=2569')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.fiscal_year', 2569);
    }

    public function test_items_are_replaced_on_update(): void
    {
        $claim = $this->createClaim();

        $payload = $this->payload();
        $payload['items'][0]['employee_id'] = $this->somying->id;
        $payload['items'][0]['first_name'] = 'สมหญิง';
        $payload['items'][0]['last_name'] = 'รักดี';

        $this->actingAs($this->finance)
            ->putJson("/api/finance/travel-expense-claims/{$claim->id}", $payload)
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.employee_id', $this->somying->id);
    }

    public function test_employee_picker_excludes_resigned_and_searches_by_citizen_id(): void
    {
        $resignedStatus = \App\Models\EmployeeStatus::create([
            'name' => 'ลาออก', 'color' => 'red', 'sort_order' => 3,
        ]);
        $activeStatus = \App\Models\EmployeeStatus::create([
            'name' => 'ปฏิบัติงานอยู่', 'color' => 'green', 'sort_order' => 1,
        ]);

        $this->somchai->update(['status_id' => $activeStatus->id]);
        $this->somying->update(['status_id' => $resignedStatus->id]);

        $response = $this->actingAs($this->finance)
            ->getJson('/api/finance/travel-expense-claims/employees')
            ->assertOk();

        $ids = array_column($response->json('data'), 'id');
        $this->assertContains($this->somchai->id, $ids);
        $this->assertNotContains($this->somying->id, $ids);

        // ค้นด้วยเลขบัตรประชาชน
        $this->actingAs($this->finance)
            ->getJson('/api/finance/travel-expense-claims/employees?search=1111111111111')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.citizen_id', '1111111111111');

        // เลขบัตรของคนที่ลาออกต้องไม่ถูกคืนกลับมา
        $this->actingAs($this->finance)
            ->getJson('/api/finance/travel-expense-claims/employees?search=2222222222222')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_expense_category_must_be_domestic_or_overseas(): void
    {
        $this->actingAs($this->finance)
            ->postJson('/api/finance/travel-expense-claims', $this->payload([
                'expense_category' => 'ค่าอื่น ๆ ที่ไม่มีในระบบ',
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('expense_category');

        $this->actingAs($this->finance)
            ->postJson('/api/finance/travel-expense-claims', $this->payload([
                'expense_category' => TravelExpenseClaim::CATEGORY_OVERSEAS,
            ]))
            ->assertCreated()
            ->assertJsonPath('data.expense_category', TravelExpenseClaim::CATEGORY_OVERSEAS);
    }

    public function test_options_returns_both_expense_categories(): void
    {
        $this->actingAs($this->finance)
            ->getJson('/api/finance/travel-expense-claims/options?fiscal_year=2569')
            ->assertOk()
            ->assertJsonCount(2, 'data.expense_categories')
            ->assertJsonPath('data.expense_categories.0.label', 'ภายในประเทศ')
            ->assertJsonPath('data.expense_categories.1.label', 'ต่างประเทศ');
    }

    public function test_hr_role_cannot_access_travel_expense_claims(): void
    {
        $hr = User::create([
            'name'     => 'HR Staff',
            'username' => 'hr_tec',
            'email'    => 'hr_tec@example.test',
            'password' => bcrypt('secret-password'),
            'role'     => 'hr',
        ]);

        $this->actingAs($hr)
            ->getJson('/api/finance/travel-expense-claims')
            ->assertForbidden();
    }

    /** สร้างเอกสารผ่าน API เพื่อให้ผ่าน validation และการออกเลขเอกสารจริง */
    protected function createClaim(array $overrides = []): TravelExpenseClaim
    {
        $status = $overrides['status'] ?? null;
        unset($overrides['status']);

        $response = $this->actingAs($this->finance)
            ->postJson('/api/finance/travel-expense-claims', $this->payload($overrides))
            ->assertCreated();

        $claim = TravelExpenseClaim::findOrFail($response->json('data.id'));

        if ($status === 'confirmed') {
            $this->actingAs($this->finance)
                ->postJson("/api/finance/travel-expense-claims/{$claim->id}/confirm")
                ->assertOk();
            $claim->refresh();
        }

        return $claim;
    }
}
