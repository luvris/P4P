<?php

namespace App\Services;

use App\Models\Import;
use App\Models\Payroll;
use App\Services\Parsers\XlsxParser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ImportService
{
    /** เก็บ row_errors ได้ไม่เกินกี่รายการ (กัน JSON บวม) */
    protected const MAX_ROW_ERRORS = 50;

    public function __construct(
        protected PayrollLinkageInspector $linkageInspector
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
     * ประมวลผลการอัปโหลดไฟล์
     *
     * @param  object|null  $parser  ส่ง parser ที่ parse ไปแล้วรอบหนึ่งได้ เพื่อไม่ต้องอ่านไฟล์ซ้ำ
     */
    public function process(string $filePath, string $fileName, int $userId, ?object $parser = null): Import
    {
        $extension = pathinfo($fileName, PATHINFO_EXTENSION);

        // สร้าง record การ import
        $import = Import::create([
            'file_name' => $fileName,
            'file_path' => $filePath,
            'file_type' => strtolower($extension),
            'uploaded_by' => $userId,
            'status' => 'processing',
        ]);

        try {
            // Parse ไฟล์
            $parser ??= $this->getParser($extension);
            $data = $parser->parse($filePath);

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
                    // แถวรวมยอด/แถวว่างในไฟล์ payroll ไม่มีเลขบัตรประชาชน
                    // ถ้าปล่อยผ่านจะถูกนับเป็นบุคลากร 1 คน และทำให้ฐานเงินสำรองพอง
                    if (trim((string) ($row['citizen_id'] ?? '')) === '') {
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

                    // ตรวจสอบข้อมูลซ้ำภายในไฟล์เดียวกัน (ชื่อ + นามสกุล + เลขบัญชี)
                    // ไม่ตรวจข้ามไฟล์ เพื่อให้นำเข้าไฟล์เดิมซ้ำเป็น snapshot ใหม่ได้
                    $exists = Payroll::where('import_id', $import->id)
                        ->where('first_name', $row['first_name'] ?? null)
                        ->where('last_name', $row['last_name'] ?? null)
                        ->where('bank_account', $row['bank_account'] ?? null)
                        ->exists();

                    if ($exists) {
                        $duplicateCount++;
                        continue;
                    }

                    Payroll::create(array_merge($row, [
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

            if ($linkWarnings !== []) {
                Log::warning('Payroll import: พบแถวที่ผูกกับทะเบียนบุคลากรไม่ได้', [
                    'import_id' => $import->id,
                    'file_name' => $fileName,
                    'count'     => count($linkWarnings),
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
            if ($rowErrors !== []) {
                DB::table('imports')
                    ->where('id', $import->id)
                    ->update(['row_errors' => json_encode($rowErrors, JSON_UNESCAPED_UNICODE)]);
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
}
