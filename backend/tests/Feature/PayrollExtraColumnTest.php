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

    /** คอลัมน์ที่มาพร้อมระบบตั้งแต่แรก */
    protected const DEFAULT_NAMES = ['ภารกิจ', 'กลุ่มงาน', 'งาน'];

    protected User $admin;

    protected User $hr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->makeUser('admin', 'ex_admin');
        $this->hr = $this->makeUser('hr', 'ex_hr');
    }

    /** เอาเฉพาะคอลัมน์ที่ประกาศเพิ่ม ตัดคอลัมน์ตั้งต้นออกให้หมด */
    protected function withoutDefaultColumns(): void
    {
        PayrollExtraColumn::whereIn('name', self::DEFAULT_NAMES)->delete();
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

        $column = PayrollExtraColumn::where('name', 'ค่าครองชีพเฉพาะหน่วย')->firstOrFail();

        // ชื่อไทยแปลงเป็น key อังกฤษไม่ได้ จึงต้องมี key สำรองที่ใช้งานได้
        $this->assertNotSame('', $column->key);
        $this->assertMatchesRegularExpression('/^[a-z0-9_]+$/', $column->key);
    }

    public function test_the_three_default_columns_come_with_the_system(): void
    {
        // ภารกิจ/กลุ่มงาน/งาน อยู่ในไฟล์จริงมานานแล้ว ต้องมาในไฟล์ต้นแบบตั้งแต่แรก
        $names = PayrollExtraColumn::active()->pluck('name')->all();

        foreach (self::DEFAULT_NAMES as $name) {
            $this->assertContains($name, $names);
        }
    }

    public function test_the_default_columns_sit_next_to_the_citizen_id(): void
    {
        // เป็นข้อมูลของคน ต้องอยู่ติดกับเลขบัตรประชาชน ไม่ใช่หลุดไปท้ายไฟล์
        $path = tempnam(sys_get_temp_dir(), 'tpl_') . '.xlsx';
        app(ImportTemplateService::class)->writePayrollTemplate($path);

        $headers = \PhpOffice\PhpSpreadsheet\IOFactory::load($path)->getActiveSheet()->toArray()[0];

        $this->assertSame(
            ['ID CARD', 'ภารกิจ', 'กลุ่มงาน', 'งาน', 'เลขที่บัญชี'],
            array_slice($headers, array_search('ID CARD', $headers, true), 5)
        );

        @unlink($path);
    }

    // ============ ตำแหน่งของคอลัมน์ในไฟล์ ============

    public function test_admin_can_choose_the_column_to_place_a_new_column_before(): void
    {
        $this->withoutDefaultColumns();

        $response = $this->actingAs($this->admin)
            ->postJson('/api/admin/payroll-extra-columns', [
                'name'          => 'ค่าครองชีพเฉพาะหน่วย',
                'before_column' => 'ตกเบิก',
            ]);

        $response->assertCreated()->assertJsonPath('data.before_column', 'ตกเบิก');

        $service = app(PayrollExtraColumnService::class);
        $service->flushCache();

        $columns = $service->columnsWithExtras(NewFormatPayrollParser::TEMPLATE_COLUMNS);
        $at = array_search('ตกเบิก', $columns, true);

        // ต้องอยู่ติดกับคอลัมน์ที่เลือก ไม่ใช่ลอยไปต่อท้ายไฟล์
        $this->assertSame('ค่าครองชีพเฉพาะหน่วย', $columns[$at - 1]);
    }

    public function test_a_column_can_be_placed_before_the_first_column_of_the_file(): void
    {
        $this->withoutDefaultColumns();

        $this->actingAs($this->admin)
            ->postJson('/api/admin/payroll-extra-columns', [
                'name'          => 'ค่าครองชีพเฉพาะหน่วย',
                'before_column' => 'ลำดับที่',
            ])
            ->assertCreated();

        $service = app(PayrollExtraColumnService::class);
        $service->flushCache();

        $columns = $service->columnsWithExtras(NewFormatPayrollParser::TEMPLATE_COLUMNS);

        // ยัดก่อนคอลัมน์แรกได้ — ซึ่ง after_column ทำไม่ได้เพราะไม่มีคอลัมน์ก่อนหน้าให้อ้าง
        $this->assertSame(['ค่าครองชีพเฉพาะหน่วย', 'ลำดับที่'], array_slice($columns, 0, 2));
    }

    public function test_columns_sharing_the_same_anchor_keep_their_order(): void
    {
        $this->withoutDefaultColumns();

        foreach ([['กลุ่มงานที่สอง', 5], ['กลุ่มงานแรก', 1]] as [$name, $sortOrder]) {
            $this->actingAs($this->admin)->postJson('/api/admin/payroll-extra-columns', [
                'name'          => $name,
                'before_column' => 'เลขที่บัญชี',
                'sort_order'    => $sortOrder,
            ])->assertCreated();
        }

        $service = app(PayrollExtraColumnService::class);
        $service->flushCache();

        $columns = $service->columnsWithExtras(NewFormatPayrollParser::TEMPLATE_COLUMNS);
        $at = array_search('เลขที่บัญชี', $columns, true);

        // คอลัมน์ที่ยึดจุดเดียวกันเรียงตาม sort_order ไม่ใช่ลำดับที่สร้าง
        $this->assertSame(
            ['กลุ่มงานแรก', 'กลุ่มงานที่สอง'],
            array_slice($columns, $at - 2, 2)
        );
    }

    public function test_changing_the_anchor_clears_the_previous_one(): void
    {
        $this->withoutDefaultColumns();

        $id = $this->actingAs($this->admin)
            ->postJson('/api/admin/payroll-extra-columns', [
                'name'         => 'ค่าครองชีพเฉพาะหน่วย',
                'after_column' => 'ID CARD',
            ])
            ->assertCreated()->json('data.id');

        $this->actingAs($this->admin)
            ->putJson("/api/admin/payroll-extra-columns/{$id}", [
                'name'          => 'ค่าครองชีพเฉพาะหน่วย',
                'before_column' => 'ตกเบิก',
                'after_column'  => null,
            ])
            ->assertOk()
            ->assertJsonPath('data.before_column', 'ตกเบิก')
            ->assertJsonPath('data.after_column', null);

        // ย้ายไปต่อท้ายไฟล์ — ตำแหน่งเดิมต้องถูกล้าง ไม่ใช่ค้างอยู่
        $this->actingAs($this->admin)
            ->putJson("/api/admin/payroll-extra-columns/{$id}", [
                'name'          => 'ค่าครองชีพเฉพาะหน่วย',
                'before_column' => null,
                'after_column'  => null,
            ])
            ->assertOk()
            ->assertJsonPath('data.before_column', null);

        $column = PayrollExtraColumn::findOrFail($id);
        $this->assertNull($column->before_column);
        $this->assertNull($column->after_column);
    }

    public function test_an_anchor_that_is_not_a_template_column_is_rejected(): void
    {
        $this->withoutDefaultColumns();

        // ชื่อผิดแล้วคอลัมน์จะหายไปจากไฟล์ต้นแบบเงียบ ๆ ต้องห้ามไม่ให้บันทึก
        $this->actingAs($this->admin)
            ->postJson('/api/admin/payroll-extra-columns', [
                'name'          => 'ค่าครองชีพเฉพาะหน่วย',
                'before_column' => 'คอลัมน์ที่ไม่มีจริง',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('before_column');
    }

    public function test_a_column_anchored_to_a_missing_name_still_reaches_the_file(): void
    {
        $this->withoutDefaultColumns();

        // ข้อมูลเก่าอาจตั้งจุดยึดที่ไม่มีในแบบฟอร์มแล้ว (เช่น คอลัมน์ถูกลบทีหลัง)
        // คอลัมน์นั้นต้องยังอยู่ในไฟล์ ไม่ใช่หายไปจนค่าที่กรอกถูกทิ้งทั้งแถว
        PayrollExtraColumn::create([
            'name'          => 'คอลัมน์กำพร้า',
            'key'           => 'orphan',
            'before_column' => 'คอลัมน์ที่ถูกลบไปแล้ว',
        ]);

        $service = app(PayrollExtraColumnService::class);
        $service->flushCache();

        $columns = $service->columnsWithExtras(NewFormatPayrollParser::TEMPLATE_COLUMNS);

        $this->assertContains('คอลัมน์กำพร้า', $columns);
        $this->assertSame('คอลัมน์กำพร้า', end($columns));
    }

    public function test_the_list_endpoint_offers_the_template_columns_as_anchor_choices(): void
    {
        $response = $this->actingAs($this->admin)->getJson('/api/admin/payroll-extra-columns');

        $response->assertOk()
            ->assertJsonPath('base_columns', NewFormatPayrollParser::TEMPLATE_COLUMNS)
            // ตำแหน่งที่บันทึกไว้ต้องส่งกลับมาให้หน้าเว็บแสดงตอนกดแก้ไข
            ->assertJsonPath('data.0.before_column', null)
            ->assertJsonPath('data.0.after_column', 'ID CARD');
    }

    public function test_only_admin_can_manage_columns(): void
    {
        $before = PayrollExtraColumn::count();

        foreach ([$this->hr, $this->makeUser('finance', 'ex_finance')] as $user) {
            $this->actingAs($user)
                ->postJson('/api/admin/payroll-extra-columns', ['name' => 'ทดสอบ'])
                ->assertForbidden();
        }

        $this->assertSame($before, PayrollExtraColumn::count());
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
        $service = app(PayrollExtraColumnService::class);
        $service->flushCache();
        $before = count(NewFormatPayrollParser::TEMPLATE_COLUMNS) + PayrollExtraColumn::active()->count();

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
        $extra = Payroll::firstOrFail()->extra_data;

        $this->assertIsArray($extra);
        $this->assertArrayNotHasKey('คอลัมน์แปลกที่ไม่ได้ประกาศ', $extra);
        $this->assertSame(array_keys($extra), PayrollExtraColumn::active()->pluck('key')->all());
    }

    public function test_import_works_when_no_extra_column_is_declared(): void
    {
        $this->withoutDefaultColumns();

        $this->actingAs($this->hr)
            ->post('/api/imports', ['file' => $this->fileWithExtras([])])
            ->assertCreated()
            ->assertJsonPath('payroll.success_rows', 1);

        $this->assertNull(Payroll::firstOrFail()->extra_data);
    }

    public function test_preview_reports_the_extra_columns(): void
    {
        PayrollExtraColumn::create(['name' => 'ค่าครองชีพเฉพาะหน่วย', 'key' => 'living', 'sort_order' => 99]);

        $response = $this->actingAs($this->hr)
            ->getJson('/api/imports/template/columns');

        $response->assertOk();

        $this->assertContains('ค่าครองชีพเฉพาะหน่วย', $response->json('data.payroll.columns'));
        // คอลัมน์ตั้งต้นมาก่อน คอลัมน์ที่เพิ่มทีหลัง
        $this->assertSame('duty', $response->json('data.payroll.extras.0.key'));
        $this->assertSame('living', $response->json('data.payroll.extras.3.key'));
        // คอลัมน์เดิมต้องยังอยู่ครบ ไม่ถูกแทนที่
        $this->assertSame(
            NewFormatPayrollParser::TEMPLATE_COLUMNS,
            $response->json('data.payroll.base_columns')
        );
    }
}