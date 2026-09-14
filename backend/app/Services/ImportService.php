<?php

namespace App\Services;

use App\Models\Import;
use App\Models\Payroll;
use App\Services\Parsers\XlsxParser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ImportService
{
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
     */
    public function process(string $filePath, string $fileName, int $userId): Import
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
            $parser = $this->getParser($extension);
            $data = $parser->parse($filePath);

            // บันทึกทีละแถว
            $successCount = 0;
            $errorCount = 0;
            $duplicateCount = 0;

            DB::beginTransaction();

            foreach ($data as $row) {
                try {
                    // ตรวจสอบข้อมูลซ้ำ (ชื่อ + นามสกุล + เลขบัญชี)
                    $exists = Payroll::where('first_name', $row['first_name'] ?? null)
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

                    $successCount++;
                } catch (\Exception $e) {
                    $errorCount++;
                    Log::warning('Import row failed', [
                        'row' => $row,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            DB::commit();

            // อัปเดตสถานะ
            $import->update([
                'total_rows' => count($data),
                'success_rows' => $successCount,
                'error_rows' => $errorCount,
                'duplicate_rows' => $duplicateCount,
                'status' => 'completed',
            ]);

            return $import;
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
