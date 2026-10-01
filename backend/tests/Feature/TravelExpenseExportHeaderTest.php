<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\TravelExpenseClaim;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * ตรวจว่า response ของ export เป็นไฟล์ xlsx จริง (ไม่ใช่ JSON error)
 * และหัวเอกสารใช้ข้อความประเภทค่าใช้จ่ายตามแบบฟอร์ม
 */
class TravelExpenseExportHeaderTest extends TestCase
{
    use RefreshDatabase;

    protected User $finance;

    protected function setUp(): void
    {
        parent::setUp();

        $this->finance = User::create([
            'name'     => 'Finance',
            'username' => 'finance_exp',
            'email'    => 'finance_exp@example.test',
            'password' => bcrypt('secret-password'),
            'role'     => 'finance',
        ]);
    }

    /**
     * สร้างใบเบิกที่ยืนยันแล้ว 1 ใบ พร้อมผู้เบิก 1 คน
     */
    protected function createConfirmedClaim(string $category): int
    {
        $employee = Employee::create([
            'citizen_id'  => '1111111111111',
            'employee_id' => 'P001',
            'first_name'  => 'สมชาย',
            'last_name'   => 'ใจดี',
        ]);

        return $this->actingAs($this->finance)
            ->postJson('/api/finance/travel-expense-claims', [
                'fiscal_year'      => 2569,
                'claim_period'     => '2026-06-01',
                'expense_category' => $category,
                'status'           => 'confirmed',
                'items'            => [[
                    'employee_id'      => $employee->id,
                    'first_name'       => 'สมชาย',
                    'last_name'        => 'ใจดี',
                    'allowance_amount' => 240,
                ]],
            ])
            ->assertCreated()
            ->json('data.id');
    }

    /**
     * อ่านค่าในชีตแรกของไฟล์ xlsx ที่ export กลับมา
     */
    protected function exportedSheet(int $id)
    {
        $response = $this->actingAs($this->finance)
            ->get("/api/finance/travel-expense-claims/{$id}/export")
            ->assertOk();

        $content = $response->streamedContent();

        $path = tempnam(sys_get_temp_dir(), 'tec_export_') . '.xlsx';
        file_put_contents($path, $content);

        try {
            return IOFactory::load($path)->getActiveSheet();
        } finally {
            @unlink($path);
        }
    }

    public function test_export_returns_real_xlsx_binary(): void
    {
        $id = $this->createConfirmedClaim(TravelExpenseClaim::CATEGORY_TRAVEL);

        $response = $this->actingAs($this->finance)
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

    public function test_export_heading_uses_full_official_category_text(): void
    {
        $id = $this->createConfirmedClaim(TravelExpenseClaim::CATEGORY_TRAVEL);

        $sheet = $this->exportedSheet($id);

        $this->assertSame('(ภาคปีงบประมาณ 2569)', $sheet->getCell('A1')->getValue());
        // ชื่อในแอปคือ "เดินทางไปราชการ" แต่บนแบบฟอร์มต้องเป็นข้อความเต็ม
        $this->assertSame(
            TravelExpenseClaim::EXCEL_CATEGORY_LABEL,
            $sheet->getCell('A2')->getValue()
        );
    }

    public function test_export_heading_uses_the_same_text_for_training_type(): void
    {
        $id = $this->createConfirmedClaim(TravelExpenseClaim::CATEGORY_TRAINING);

        $sheet = $this->exportedSheet($id);

        // ทั้งสองประเภทพิมพ์ข้อความเดียวกัน
        $this->assertSame(
            'ค่าเบี้ยเลี้ยง ค่าที่พัก ค่าพาหนะ ภายในประเทศ',
            $sheet->getCell('A2')->getValue()
        );
    }
}
