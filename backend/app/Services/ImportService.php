<?php

namespace App\Services;

use App\Models\Import;
use App\Models\Payroll;
use App\Services\Parsers\NewFormatPayrollParser;
use App\Services\Parsers\XlsxParser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;


class ImportService
{
    /** เก็บ row_errors ได้ไม่เกินกี่รายการ (กัน JSON บวม) */
    protected const MAX_ROW_ERRORS = 50;

    public function __construct(
        protected PayrollLinkageInspector $linkageInspector,
        protected PayrollExtraColumnService $extraColumns
    ) {}

    /**
     * ดึง Parser ที่เหมาะสมกับนามสกุลไฟล์
     */
    protected function getParser(string $extension)
    {
        return match (strtolower($extension)) {
            'xlsx', 'xls' => new XlsxParser(),
            // 'txt' => new TxtParser(),
            default => throw new \Exception("ไม่รองรับไฟล์ .$extension"),
        };
    }

    /**
     * เลือก parser ตามรูปแบบไฟล์จริง
     *
     * ไฟล์รูปแบบใหม่ (39 คอลัมน์, งวดอยู่ในแต่ละแถว) ใช้คนละ parser กับไฟล์รูปแบบเดิม
     * เพื่อให้ทั้งสองรูปแบบนำเข้าได้จากช่องเดียวกัน
     */
    protected function resolveParser(string $extension, string $filePath)
    {
        $parser = $this->getParser($extension);

        if (in_array(strtolower($extension), ['xlsx', 'xls'], true)
            && NewFormatPayrollParser::looksLikeNewFormat($filePath)) {
            return new NewFormatPayrollParser();
        }

        return $parser;
    }

    /**
     * ประมวลผลการอัปโหลดไฟล์
     *
     * @param  object|null  $parser  ส่ง parser ที่ parse ไปแล้วรอบหนึ่งได้ เพื่อไม่ต้องอ่านไฟล์ซ้ำ
     * @param  string|null  $storedPath  พาธที่เก็บไฟล์จริงใน storage (ถ้ารู้) — ใช้บันทึกลง imports
     *                                             ให้ชี้ไฟล์เดียวกับฝั่งทะเบียนบุคลากร
     * @param  array|null  $period  งวดของข้อมูล { fiscal_year, period_month, period_year }
     */
    public function process(
        string $filePath,
        string $fileName,
        int $userId,
        ?object $parser = null,
        ?array $period = null,
        ?string $storedPath = null
    ): Import {
        $extension = pathinfo($fileName, PATHINFO_EXTENSION);

        // สร้าง record การ import
        $import = Import::create([
            'file_name' => $fileName,
            'file_path' => $storedPath ?? $filePath,
            'file_type' => strtolower($extension),
            'uploaded_by' => $userId,
            'status' => 'processing',
            'fiscal_year' => $period['fiscal_year'] ?? null,
            'period_month' => $period['period_month'] ?? null,
            'period_year' => $period['period_year'] ?? null,
        ]);

        try {
            // Parse ไฟล์
            $parser ??= $this->resolveParser($extension, $filePath);
            $data = $parser->parse($filePath);

            // ไฟล์รูปแบบใหม่อ่านงวดจากแต่ละแถว — สรุปงวดของไฟล์เพื่อบันทึกที่ imports
            $filePeriod = $this->summarizePeriod($data);

            if ($filePeriod !== null && $period === null) {
                $period = $filePeriod;

                $import->update([
                    'fiscal_year'  => $period['fiscal_year'],
                    'period_month' => $period['period_month'],
                    'period_year'  => $period['period_year'],
                ]);
            }

            // ไฟล์ payroll รูปแบบใหม่ไม่มีคอลัมน์เลขบัตรประชาชน
            // → หาเลขบัตรจากทะเบียนบุคลากรด้วยชื่อ-นามสกุล ก่อนตัดสินว่าแถวไหนคือ "แถวรวมยอด"
            // (ถ้าไม่ derive ทุกแถวจะไม่มีเลขบัตรและถูกข้ามทั้งไฟล์)
            $data = $this->linkageInspector->deriveCitizenIdsByName($data);

            // เตือนถ้าคอลัมน์รายรับที่ใช้คำนวณเงินสำรองหายไปจากไฟล์ (จะกลายเป็น 0)
            if (method_exists($parser, 'missingReserveIncomeFields')) {
                $missing = $parser->missingReserveIncomeFields();

                if ($missing !== []) {
                    Log::warning('Payroll import: ไม่พบคอลัมน์รายรับที่ใช้คำนวณเงินสำรอง', [
                        'import_id' => $import->id,
                        'file_name' => $fileName,
                        'missing'   => $missing,
                    ]);
                }
            }

            // บันทึกทีละแถว
            $successCount = 0;
            $errorCount = 0;
            $duplicateCount = 0;
            $skippedCount = 0;
            $rowErrors = [];
            $insertedRows = [];

            DB::beginTransaction();

            foreach ($data as $index => $row) {
                try {
                    // แถวรวมยอด/แถวว่างในไฟล์ payroll ไม่มีทั้งเลขบัตรประชาชนและชื่อ-นามสกุล
                    // ถ้าปล่อยผ่านจะถูกนับเป็นบุคลากร 1 คน และทำให้ฐานเงินสำรองพอง
                    //
                    // แถวที่ "มีชื่อ-นามสกุล" แต่ derive เลขบัตรไม่ได้ (ไฟล์รูปแบบใหม่
                    // ไม่มีคอลัมน์เลขบัตร และชื่อไม่ตรงทะเบียน) ต้อง import ด้วยเลขว่าง
                    // เพราะเป็นคนจริง — ระบบเงินสำรองจะจัดกลุ่ม "ไม่ระบุ" ให้เอง
                    $hasName = trim((string) ($row['first_name'] ?? '')) !== ''
                        || trim((string) ($row['last_name'] ?? '')) !== '';

                    if (trim((string) ($row['citizen_id'] ?? '')) === '' && ! $hasName) {
                        $skippedCount++;

                        if (count($rowErrors) < self::MAX_ROW_ERRORS) {
                            $rowErrors[] = [
                                'row'   => $index + 2, // +2 = เผื่อ header row และ index เริ่มที่ 0
                                'type'  => 'payroll',
                                'error' => 'ไม่มีเลขบัตรประชาชน (แถวรวมยอดหรือแถวว่าง) จึงข้าม',
                            ];
                        }

                        continue;
                    }

                    // ตรวจสอบข้อมูลซ้ำภายในไฟล์เดียวกัน (ชื่อ + นามสกุล + เลขบัญชี + งวด)
                    // ไม่ตรวจข้ามไฟล์ เพื่อให้นำเข้าไฟล์เดิมซ้ำเป็น snapshot ใหม่ได้
                    //
                    // ต้องรวม "งวด" ในการเทียบซ้ำ เพราะไฟล์รูปแบบใหม่รวมหลายงวดไว้ในไฟล์เดียว
                    // (เช่น ต.ค.–ก.ย. ของคนเดิม) ถ้าไม่รวมงวด คนเดิมทุกเดือนจะถูกนับซ้ำ
                    // เหลืองวดเดียว ทำให้ข้อมูลทั้งปีหายไปเงียบ ๆ
                    $duplicateQuery = Payroll::where('import_id', $import->id)
                        ->where('first_name', $row['first_name'] ?? null)
                        ->where('last_name', $row['last_name'] ?? null)
                        ->where('bank_account', $row['bank_account'] ?? null);

                    // ไฟล์ที่มีคอลัมน์งวดเป็นรายแถว → เทียบงวดด้วย
                    if (array_key_exists('fiscal_year', $row) || array_key_exists('period_month', $row)) {
                        $duplicateQuery
                            ->where('fiscal_year', $row['fiscal_year'] ?? null)
                            ->where('period_month', $row['period_month'] ?? null);
                    }

                    $exists = $duplicateQuery->exists();

                    if ($exists) {
                        $duplicateCount++;
                        continue;
                    }

                    // ค่าคอลัมน์ที่ผู้ใช้เพิ่มเอง — กรองให้เหลือเฉพาะคอลัมน์ที่ประกาศไว้
                    // และแปลงตามชนิด ค่าที่ไม่ผ่านการตรวจจะถูกทิ้งไปแทนที่จะเขียนข้อมูลเสีย
                    $attributes = $row;

                    if (isset($row['extra_data'])) {
                        $attributes['extra_data'] = $this->extraColumns->normalizeValues($row['extra_data']) ?: null;
                    }

                    Payroll::create(array_merge($attributes, [
                        'import_id' => $import->id,
                    ]));

                    // เก็บแถวที่นำเข้าจริงไว้ตรวจการผูกกับทะเบียนบุคลากร (ใช้ index เดิมเป็นเลขแถว)
                    $insertedRows[$index] = $row;

                    $successCount++;
                } catch (\Exception $e) {
                    $errorCount++;
                    $rowErrors[] = [
                        'row'   => $index + 2,
                        'type'  => 'payroll',
                        'error' => $e->getMessage(),
                    ];
                    Log::warning('Import row failed', [
                        'row' => $row,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            DB::commit();

            // ตรวจว่าแถวที่นำเข้าผูกกับทะเบียนบุคลากรได้หรือไม่
            // เลขบัตรประชาชนที่พิมพ์ผิดจะทำให้คนนั้นถูกตัดออกจากเงินสำรองอย่างเงียบ ๆ
            $linkWarnings = $this->linkageInspector->inspect($insertedRows);

            // สรุปยอดที่จะหายไปจากฐานเงินสำรอง — เก็บไว้ให้ผู้ใช้เห็นทันทีที่นำเข้า
            $unlinked = $this->linkageInspector->summarizeUnlinked($insertedRows);

            if ($unlinked['rows'] > 0) {
                Log::warning('Payroll import: มีแถวที่ไม่เข้าฐานเงินสำรอง', [
                    'import_id'    => $import->id,
                    'file_name'    => $fileName,
                    'rows'         => $unlinked['rows'],
                    'total_income' => $unlinked['total_income'],
                    'by_reason'    => $unlinked['by_reason'],
                ]);
            }

            $rowErrors = array_slice(array_merge($rowErrors, $linkWarnings), 0, self::MAX_ROW_ERRORS);

            // อัปเดตสถานะ
            $import->update([
                'total_rows' => count($data),
                'success_rows' => $successCount,
                'error_rows' => $errorCount,
                'duplicate_rows' => $duplicateCount,
                'skipped_rows' => $skippedCount,
                'status' => 'completed',
            ]);

            // row_errors ไม่อยู่ใน $fillable ของ Import (ไม่แก้ model เดิม)
            // จึงเขียนตรงผ่าน query builder เฉพาะ import แถวนี้
            // แนบยอดที่หายไปจากฐานเงินสำรองไว้ในรายการเดียวกัน
            $storedErrors = array_slice($rowErrors, 0, self::MAX_ROW_ERRORS);

            if ($unlinked['rows'] > 0) {
                $storedErrors[] = [
                    'row'          => 0,
                    'type'         => 'unlinked_summary',
                    'unlinked_rows'         => $unlinked['rows'],
                    'unlinked_total_income' => $unlinked['total_income'],
                    'by_reason'    => $unlinked['by_reason'],
                    'samples'      => $unlinked['samples'],
                    'error'        => sprintf(
                        'มี %d คน-งวด รวม %s บาท ที่นำเข้าสำเร็จแต่ไม่ถูกนับในฐานเงินสำรอง เพราะผูกกับทะเบียนบุคลากรไม่ได้',
                        $unlinked['rows'],
                        number_format($unlinked['total_income'], 2)
                    ),
                ];
            }

            if ($storedErrors !== []) {
                DB::table('imports')
                    ->where('id', $import->id)
                    ->update(['row_errors' => json_encode($storedErrors, JSON_UNESCAPED_UNICODE)]);
            }

            return $import->fresh();
        } catch (\Exception $e) {
            DB::rollBack();

            $import->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * สรุปงวดของไฟล์จากงวดที่แต่ละแถวระบุไว้
     *
     * ถ้าทุกแถวเป็นงวดเดียวกัน → ใช้งวดนั้น
     * ถ้าหลายงวดปนกัน → ใช้งวดแรกที่พบ แต่เตือนให้ผู้ใช้ตรวจ
     *
     * @param  array<int, array<string, mixed>>  $data
     * @return array{fiscal_year: int, period_month: int, period_year: int}|null
     */
    protected function summarizePeriod(array $data): ?array
    {
        $periods = [];

        foreach ($data as $row) {
            $fiscalYear = $row['fiscal_year'] ?? null;
            $month = $row['period_month'] ?? null;

            if ($fiscalYear === null || $month === null) {
                continue;
            }

            $periods[(int) $fiscalYear . '-' . (int) $month] = [
                'fiscal_year'  => (int) $fiscalYear,
                'period_month' => (int) $month,
                'period_year'  => (int) ($row['period_year'] ?? $fiscalYear),
            ];
        }

        if ($periods === []) {
            return null;
        }

        if (count($periods) > 1) {
            Log::warning('Payroll import: ไฟล์เดียวมีหลายงวดปนกัน ใช้งวดแรกเป็นงวดของไฟล์', [
                'periods' => array_values($periods),
            ]);
        }

        return reset($periods);
    }
}
