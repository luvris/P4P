<?php

namespace Tests\Feature;

use App\Services\ImportTemplateService;
use App\Services\NewFormatEmployeeImportService;
use App\Services\Parsers\DutyAssignmentXlsxParser;
use App\Services\Parsers\NewFormatPayrollParser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ไฟล์ต้นแบบนำเข้าข้อมูล
 *
 * ข้อสำคัญ: ไฟล์ที่ดาวน์โหลดได้ต้องผ่านการอัปโหลดจริงโดยไม่ error
 * เทสต์นี้จึงยิงไฟล์ที่สร้างเข้า parser ตัวจริงทุกครั้ง
 */
class ImportTemplateTest extends TestCase
{
    use RefreshDatabase;

    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        parent::tearDown();
    }

    private function payrollTemplatePath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'template') . '.xlsx';
        app(ImportTemplateService::class)->writePayrollTemplate($path);
        $this->tempFiles[] = $path;

        return $path;
    }

    private function user(string $role, string $username): User
    {
        return User::create([
            'name'     => 'Template ' . $role,
            'username' => $username,
            'email'    => $username . '@example.test',
            'password' => bcrypt('secret-password'),
            'role'     => $role,
        ]);
    }

    public function test_payroll_template_is_recognised_as_the_new_format(): void
    {
        $path = $this->payrollTemplatePath();

        $this->assertTrue(
            NewFormatPayrollParser::looksLikeNewFormat($path),
            'ไฟล์ต้นแบบต้องถูกระบบมองว่าเป็นไฟล์เงินเดือนรูปแบบใหม่'
        );
    }

    public function test_payroll_template_parses_without_errors(): void
    {
        $path = $this->payrollTemplatePath();

        $parser = new NewFormatPayrollParser();
        $rows = $parser->parse($path);

        $this->assertNotEmpty($rows, 'ไฟล์ต้นแบบต้องมีแถวตัวอย่างให้ parser อ่านได้');
        $this->assertSame(
            [],
            $parser->missingReserveIncomeFields(),
            'ไฟล์ต้นแบบต้องมีคอลัมน์ฐานเงินสำรองครบ'
        );
    }

    public function test_payroll_template_sample_rows_have_usable_values(): void
    {
        $path = $this->payrollTemplatePath();
        $row = (new NewFormatPayrollParser())->parse($path)[0];

        // งวดต้องอ่านได้จริง ไม่ใช่ null
        $this->assertNotNull($row['fiscal_year'], 'ต้องอ่านปีงบได้จากคอลัมน์ ปี/เดือน');
        $this->assertNotNull($row['period_month']);
        $this->assertGreaterThanOrEqual(1, $row['period_month']);
        $this->assertLessThanOrEqual(12, $row['period_month']);

        $this->assertNotSame('', trim((string) $row['first_name']));
        $this->assertNotSame('', trim((string) $row['last_name']));

        // ฐานเงินสำรองต้องเป็นตัวเลข
        $this->assertIsFloat($row['total_income']);
        $this->assertGreaterThan(0, $row['total_income']);
    }

    public function test_payroll_template_uses_only_synthetic_sample_data(): void
    {
        $path = $this->payrollTemplatePath();
        $row = (new NewFormatPayrollParser())->parse($path)[0];

        // ต้องไม่ใช่เลขบัตร/บัญชีจริง — กันคนแล้วเผลออัปข้อมูลจริงลงไฟล์ต้นแบบ
        $this->assertSame('0000000000000', $row['citizen_id']);
        $this->assertSame('0000000000', $row['bank_account']);
    }

    public function test_payroll_template_can_be_read_by_the_hr_import(): void
    {
        $path = $this->payrollTemplatePath();

        $result = app(NewFormatEmployeeImportService::class)->parse($path);

        $this->assertNotEmpty($result['data'], 'ฝั่งบุคลากรต้องอ่านไฟล์ต้นแบบได้');
        $this->assertSame([], $result['warnings']);
    }

    public function test_template_header_covers_every_parser_column(): void
    {
        // parser เทียบชื่อคอลัมน์แบบ normalize (ตัวเล็ก/ขีด/ช่องว่าง) จึงต้องเทียบแบบเดียวกัน
        $normalize = fn (string $name) => trim(preg_replace(
            '/\s+/u',
            ' ',
            str_replace(['_', '-'], ' ', mb_strtolower(trim($name)))
        ));

        $columns = array_map($normalize, NewFormatPayrollParser::TEMPLATE_COLUMNS);

        foreach (NewFormatPayrollParser::readableColumns() as $readable) {
            $this->assertContains(
                $normalize($readable),
                $columns,
                "คอลัมน์ '{$readable}' ที่ parser อ่านได้ ต้องมีในไฟล์ต้นแบบ"
            );
        }

        $this->assertCount(39, NewFormatPayrollParser::TEMPLATE_COLUMNS);
    }

    public function test_template_months_are_all_accepted_by_the_parser(): void
    {
        foreach (NewFormatPayrollParser::TEMPLATE_MONTHS as $month) {
            $this->assertContains(
                $month,
                NewFormatPayrollParser::acceptedMonthLabels(),
                "ชื่อเดือน '{$month}' ในไฟล์ต้นแบบต้องเป็นที่ parser รับได้"
            );
        }
    }

    // ============ endpoint ============

    public function test_finance_can_download_the_payroll_template(): void
    {
        // หน้าอัปโหลดย้ายมากลาง ใช้ได้ทุก role — การเงินต้องยังดาวน์โหลดได้
        $response = $this->actingAs($this->user('finance', 'tpl_finance'))
            ->get('/api/imports/template');

        $response->assertOk();
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_hr_can_download_the_payroll_template(): void
    {
        $this->actingAs($this->user('hr', 'tpl_hr'))
            ->get('/api/imports/template')
            ->assertOk();
    }

    public function test_template_download_requires_login(): void
    {
        $this->get('/api/imports/template')->assertUnauthorized();
    }

    public function test_template_route_is_not_treated_as_an_import_id(): void
    {
        // "template" เป็นคำที่ไม่ใช่ตัวเลข ต้องไม่ไปตกที่ /imports/{import}
        $this->actingAs($this->user('finance', 'tpl_finance2'))
            ->get('/api/imports/template')
            ->assertOk();
    }

    public function test_the_old_department_specific_import_routes_are_gone(): void
    {
        // หน้าอัปโหลดรวมแล้ว ต้องไม่เหลือ route แยกตามแผนกที่หลอกว่ายังใช้ได้
        foreach (['/api/hr/imports/template', '/api/finance/imports/template'] as $route) {
            $this->actingAs($this->user('admin', 'tpl_gone_' . md5($route)))
                ->getJson($route)
                ->assertNotFound();
        }
    }

    public function test_columns_endpoint_lists_the_template_columns(): void
    {
        $response = $this->actingAs($this->user('hr', 'tpl_hr3'))
            ->getJson('/api/imports/template/columns');

        $response->assertOk()
            ->assertJsonPath('data.payroll.columns', NewFormatPayrollParser::TEMPLATE_COLUMNS)
            ->assertJsonPath('data.payroll.required', NewFormatPayrollParser::CRITICAL_COLUMNS);
    }
}
