<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\ImportTemplateController;
use App\Services\ImportTemplateService;
use App\Services\NewFormatEmployeeImportService;
use App\Services\Parsers\NewFormatPayrollParser;
use Tests\TestCase;

/**
 * ยืนยันว่า "ไบต์ที่เบราว์เซอร์ได้รับ" อ่านได้จริง
 *
 * การเขียนไฟล์ต้นแบบลงดิสก์แล้ว parse ผ่าน ยังไม่พอ
 * ต้องตรวจเส้นทางจริงคือ streamed response ด้วย
 */
class ImportTemplateStreamTest extends TestCase
{
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

    /** ดักเอา��์์ดจาก callback ของ StreamedResponse */
    private function capture(\Symfony\Component\HttpFoundation\StreamedResponse $response): string
    {
        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }

    private function save(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'streamed') . '.xlsx';
        file_put_contents($path, $bytes);
        $this->tempFiles[] = $path;

        return $path;
    }

    private function controller(): ImportTemplateController
    {
        return new ImportTemplateController(app(ImportTemplateService::class));
    }

    public function test_streamed_payroll_template_parses_without_errors(): void
    {
        $response = $this->controller()->payroll();
        $bytes = $this->capture($response);

        $this->assertNotSame('', $bytes, 'ต้องส่งเนื้อหาไฟล์ออกไปจริง');
        $this->assertSame('PK', substr($bytes, 0, 2), 'ต้องเป็นไฟล์ xlsx (zip)');

        $path = $this->save($bytes);
        $parser = new NewFormatPayrollParser();
        $rows = $parser->parse($path);

        $this->assertNotEmpty($rows, 'ไบต์ที่ส่งออกไปต้อง parse ได้');
        $this->assertSame([], $parser->missingReserveIncomeFields());
    }

    public function test_streamed_payroll_template_is_readable_by_the_hr_import(): void
    {
        $path = $this->save($this->capture($this->controller()->payroll()));

        $result = app(NewFormatEmployeeImportService::class)->parse($path);

        $this->assertNotEmpty($result['data']);
        $this->assertSame([], $result['warnings']);
    }

    public function test_download_uses_a_thai_file_name(): void
    {
        $disposition = (string) $this->controller()->payroll()
            ->headers->get('content-disposition');

        // เซิร์ฟเวอร์อาจแปลง "UTF-8" เป็นตัวพิมพ์เล็ก — จึงเทียบแบบไม่สนตัวพิมพ์
        $this->assertSame(
            1,
            preg_match("/filename\*=utf-8''([^;]+)/i", $disposition, $matches),
            "content-disposition ต้องมีชื่อไฟล์ UTF-8: {$disposition}"
        );

        $this->assertSame(
            'แบบฟอร์มนำเข้าข้อมูลเงินเดือน.xlsx',
            rawurldecode($matches[1])
        );
    }
}
