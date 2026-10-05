<?php

namespace Tests\Feature;

use App\Models\Payroll;
use App\Models\PayrollExtraColumn;
use App\Models\User;
use App\Services\ImportTemplateService;
use App\Services\PayrollExtraColumnService;
use App\Services\Parsers\NewFormatPayrollParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Tests\TestCase;

/**
 * คอลัมน์เพิ่มเติมที่ผู้ใช้ประกาศเอง
 *
 * ต้องผ่านวงจรครบ: ประกาศคอลัมน์ → ได้ในไฟล์ต้นแบบ → อัปโหลดแล้วค่าถูกเก็บ
 */
class PayrollExtraColumnTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $hr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->makeUser('admin', 'ex_admin');
        $this->hr = $this->makeUser('hr', 'ex_hr');
    }

    protected function makeUser(string $role, string $username): User
    {
        return User::create([
            'name'     => $role . ' tester',
            'username' => $username,
            'email'    => $username . '@example.test',
            'password' => bcrypt('secret-password'),
            'role'     => $role,
        ]);
    }

    /**
     * ไฟล์เงินเดือนที่มีคอลัมน์เพิ่มต่อท้าย
     *
     * @param  array<string, string>  $extras  ชื่อคอลัมน์ (ไม่รวม 39 คอลัมน์เดิม) => ค่า
     */
    protected function fileWithExtras(array $extras): UploadedFile
    {
        $columns = NewFormatPayrollParser::TEMPLATE_COLUMNS;
        $row = [
            'ลำดับที่' => 1, 'ปี' => 2569, 'เดือน' => 'มกราคม',
            'คำนำหน้า' => 'นาย', 'ชื่อ' => 'ทดสอบ', 'นามสกุล' => 'คอลัมน์เพิ่ม',
            'ประเภท' => 'ข้าราชการ', 'ตำแหน่ง' => 'พยาบาล', 'ตำแหน่งเลขที่' => '101',
            'ID CARD' => '9991111111111', 'เลขที่บัญชี' => '012-000-001',
            'เงินเดือน' => 30000, 'รวมรายรับทางตรง' => 30000,
            'รวมรายรับทางอ้อม' => 600, 'ยอดรวมรายรับทั้งหมด รายบุคคล' => 30600,
        ];

        $columns = array_merge($columns, array_keys($extras));
        $values = array_merge($row, $extras);

        $spreadsheet = new Spreadsheet();
        $spreadsheet->getActiveSheet()->fromArray([
            $columns,
            array_map(fn ($c) => $values[$c] ?? null, $columns),
        ], null, 'A1');

        $path = tempnam(sys_get_temp_dir(), 'extras_') . '.xlsx';
        (new XlsxWriter($spreadsheet))->save($path);

        return new UploadedFile($path, 'with_extras.xlsx', null, null, true);
    }

    // ============ การจัดการคอลัมน์ ============

    public function test_admin_can_add_a_column_and_it_gets_a_key(): void
    {
        $response = $this->actingAs($this->admin)
            ->postJson('/api/admin/payroll-extra-columns', [
                'name'        => 'ค่าครองชีพเฉพาะหน่วย',
                'data_type'   => 'number',
                'description' => 'ยอดเฉพาะหน่วยนี้',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'ค่าครองชีพเฉพาะหน่วย')
            ->assertJsonPath('data.data_type', 'number');

        $column = PayrollExtraColumn::firstOrFail();

        // ชื่อไทยแปลงเป็น key อังกฤษไม่ได้ จึงต้องมี key สำรองที่ใช้งานได้
        $this->assertNotSame('', $column->key);
        $this->assertMatchesRegularExpression('/^[a-z0-9_]+$/', $column->key);
    }

    public function test_only_admin_can_manage_columns(): void
    {
        foreach ([$this->hr, $this->makeUser('finance', 'ex_finance')] as $user) {
            $this->actingAs($user)
                ->postJson('/api/admin/payroll-extra-columns', ['name' => 'ทดสอบ'])
                ->assertForbidden();
        }

        $this->assertSame(0, PayrollExtraColumn::count());
    }

    public function test_column_names_must_be_unique(): void
    {
        PayrollExtraColumn::create(['name' => 'คอลัมน์ซ้ำ', 'key' => 'dup']);

        $this->actingAs($this->admin)
            ->postJson('/api/admin/payroll-extra-columns', ['name' => 'คอลัมน์ซ้ำ'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    public function test_disabling_a_column_keeps_the_data(): void
    {
        $column = PayrollExtraColumn::create([
            'name' => 'เลิกใช้แล้ว', 'key' => 'retired', 'is_active' => true,
        ]);

        $this->actingAs($this->admin)
            ->deleteJson("/api/admin/payroll-extra-columns/{$column->id}")
            ->assertOk();

        $column->refresh();
        $this->assertFalse($column->is_active);

        // ปิดใช้งานแล้วไม่อยู่ในไฟล์ต้นแบบ แต่แถวที่เก็บไว้ยังอ่านได้
        $service = app(PayrollExtraColumnService::class);
        $service->flushCache();

        $this->assertNotContains('เลิกใช้แล้ว', $service->columnsWithExtras([]));
    }

    // ============ ไฟล์ต้นแบบ ============

    public function test_the_template_gains_the_new_columns(): void
    {
        $before = count(NewFormatPayrollParser::TEMPLATE_COLUMNS);

        PayrollExtraColumn::create([
            'name' => 'ค่าครองชีพเฉพาะหน่วย', 'key' => 'living', 'sort_order' => 1,
        ]);

        $path = tempnam(sys_get_temp_dir(), 'tpl_') . '.xlsx';
        app(ImportTemplateService::class)->writePayrollTemplate($path);

        // แถวที่ 1 ของไฟล์คือหัวตาราง (toArray() เริ่มนับจาก 0)
        $headers = \PhpOffice\PhpSpreadsheet\IOFactory::load($path)->getActiveSheet()->toArray()[0];

        $this->assertCount($before + 1, $headers);
        $this->assertContains('ค่าครองชีพเฉพาะหน่วย', $headers);

        // ไฟล์ต้นแบบที่มีคอลัมน์เพิ่ม ต้องยังผ่านการอ่านของ parser เหมือนเดิม
        $parsed = (new NewFormatPayrollParser())->parse($path);
        $this->assertNotEmpty($parsed);

        @unlink($path);
    }

    public function test_a_disabled_column_is_not_added_to_the_template(): void
    {
        PayrollExtraColumn::create([
            'name' => 'ไม่ใช้แล้ว', 'key' => 'unused', 'is_active' => false,
        ]);

        $service = app(PayrollExtraColumnService::class);
        $service->flushCache();

        $this->assertNotContains('ไม่ใช้แล้ว', $service->columnsWithExtras([]));
    }

    // ============ การอ่านค่าจากไฟล์ที่อัปโหลด ============

    public function test_uploaded_values_are_stored_per_row(): void
    {
        $column = PayrollExtraColumn::create([
            'name' => 'ค่าครองชีพเฉพาะหน่วย', 'key' => 'living', 'data_type' => 'number',
        ]);

        $this->actingAs($this->hr)
            ->post('/api/imports', [
                'file' => $this->fileWithExtras(['ค่าครองชีพเฉพาะหน่วย' => '1,500']),
            ])
            ->assertCreated();

        $payroll = Payroll::firstOrFail();

        $this->assertSame('1500', $payroll->extra_data[$column->key]);
    }

    public function test_a_value_that_does_not_match_the_declared_type_is_dropped(): void
    {
        $column = PayrollExtraColumn::create([
            'name' => 'ค่าครองชีพเฉพาะหน่วย', 'key' => 'living', 'data_type' => 'number',
        ]);

        $this->actingAs($this->hr)
            ->post('/api/imports', [
                'file' => $this->fileWithExtras(['ค่าครองชีพเฉพาะหน่วย' => 'ไม่ใช่ตัวเลข']),
            ])
            ->assertCreated();

        // ค่าที่ผ่านการตรวจไม่ได้ต้องเป็น null ไม่ใช่ข้อความเสีย
        $this->assertNull(Payroll::firstOrFail()->extra_data[$column->key]);
    }

    public function test_text_columns_keep_their_value_verbatim(): void
    {
        $column = PayrollExtraColumn::create([
            'name' => 'หมายเหตุหน่วย', 'key' => 'note_unit', 'data_type' => 'text',
        ]);

        $this->actingAs($this->hr)
            ->post('/api/imports', [
                'file' => $this->fileWithExtras(['หมายเหตุหน่วย' => 'ย้ายหน่วย มิ.ย. 2569']),
            ])
            ->assertCreated();

        $this->assertSame('ย้ายหน่วย มิ.ย. 2569', Payroll::firstOrFail()->extra_data[$column->key]);
    }

    public function test_a_column_not_declared_in_settings_is_ignored(): void
    {
        // ผู้ใช้ยังไม่ได้ประกาศคอลัมน์นี้ แต่ยัดคอลัมน์แปลกลงในไฟล์
        $this->actingAs($this->hr)
            ->post('/api/imports', [
                'file' => $this->fileWithExtras(['คอลัมน์แปลกที่ไม่ได้ประกาศ' => 'ข้อมูลลอย ๆ']),
            ])
            ->assertCreated();

        // คอลัมน์ที่ไม่ได้ประกาศต้องไม่ถูกเก็บ ไม่งั้นข้อมูลจะปนเข้ามาโดยไม่มีที่มา
        $this->assertNull(Payroll::firstOrFail()->extra_data);
    }

    public function test_import_works_when_no_extra_column_is_declared(): void
    {
        $this->actingAs($this->hr)
            ->post('/api/imports', ['file' => $this->fileWithExtras([])])
            ->assertCreated()
            ->assertJsonPath('payroll.success_rows', 1);

        $this->assertNull(Payroll::firstOrFail()->extra_data);
    }

    public function test_preview_reports_the_extra_columns(): void
    {
        PayrollExtraColumn::create(['name' => 'ค่าครองชีพเฉพาะหน่วย', 'key' => 'living']);

        $response = $this->actingAs($this->hr)
            ->getJson('/api/imports/template/columns');

        $response->assertOk();

        $this->assertContains('ค่าครองชีพเฉพาะหน่วย', $response->json('data.payroll.columns'));
        $this->assertSame('living', $response->json('data.payroll.extras.0.key'));
        // คอลัมน์เดิมต้องยังอยู่ครบ ไม่ถูกแทนที่
        $this->assertSame(
            NewFormatPayrollParser::TEMPLATE_COLUMNS,
            $response->json('data.payroll.base_columns')
        );
    }
}