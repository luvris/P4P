<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\TravelExpenseClaim;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ตรวจว่า response ของ export เป็นไฟล์ xlsx จริง (ไม่ใช่ JSON error)
 */
class TravelExpenseExportHeaderTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_returns_real_xlsx_binary(): void
    {
        $finance = User::create([
            'name'     => 'Finance',
            'username' => 'finance_exp',
            'email'    => 'finance_exp@example.test',
            'password' => bcrypt('secret-password'),
            'role'     => 'finance',
        ]);

        $employee = Employee::create([
            'citizen_id'  => '1111111111111',
            'employee_id' => 'P001',
            'first_name'  => 'สมชาย',
            'last_name'   => 'ใจดี',
        ]);

        $created = $this->actingAs($finance)
            ->postJson('/api/finance/travel-expense-claims', [
                'fiscal_year'      => 2569,
                'claim_period'     => '2026-06-01',
                'expense_category' => TravelExpenseClaim::CATEGORY_DOMESTIC,
                'status'           => 'confirmed',
                'items'            => [[
                    'employee_id'      => $employee->id,
                    'first_name'       => 'สมชาย',
                    'last_name'        => 'ใจดี',
                    'allowance_amount' => 240,
                ]],
            ])
            ->assertCreated();

        $id = $created->json('data.id');

        $response = $this->actingAs($finance)
            ->get("/api/finance/travel-expense-claims/{$id}/export")
            ->assertOk();

        $content = $response->streamedContent();

        // ไฟล์ xlsx เป็น zip — ขึ้นต้นด้วย PK
        $this->assertSame('PK', substr($content, 0, 2), 'response ไม่ใช่ไฟล์ xlsx');
        $this->assertStringContainsString(
            'spreadsheetml.sheet',
            $response->headers->get('content-type')
        );
        $this->assertStringContainsString(
            "travel-expense-claim-TEC-2569-0001-FY2569.xlsx",
            $response->headers->get('content-disposition')
        );
    }
}
