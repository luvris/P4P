<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeType;
use App\Models\Import;
use App\Models\Prefix;
use App\Services\Parsers\NewFormatPayrollParser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * นำเข้าทะเบียนบุคลากรจากไฟล์เงินเดือนรูปแบบใหม่ (39 คอลัมน์)
 *
 * คอลัมน์ด้านบุคลากรในไฟล์:
 *   คำนำหน้า | ชื่อ | นามสกุล | ประเภท | ตำแหน่ง | ตำแหน่งเลขที่
 *   ID CARD | เลขที่บัญชี | เลขที่บัญชี.1
 *
 * ไฟล์นี้รวมหลายงวด คนเดิมจึงปรากฏหลายแถว — ใช้ ID CARD เป็นกุญแจ
 * เก็บเฉพาะข้อมูลของงวดล่าสุด (เดือน/ปีมากที่สุด) เพื่อไม่ให้ข้อมูลเก่าทับของใหม่
 */
class NewFormatEmployeeImportService
{
    /** เก็บ row error ได้ไม่เกินกี่รายการ (กัน JSON บวม) */
    protected const MAX_ROW_ERRORS = 50;

    /**
     * อ่านไฟล์ แล้วยุบเป็น 1 แถวต่อคน (งวดล่าสุด)
     *
     * @return array{data: array<int, array<string, mixed>>, warnings: array<int, string>}
     */
    public function parse(string $filePath): array
    {
        $rows = (new NewFormatPayrollParser())->parse($filePath);

        $latest = [];
        $warnings = [];

        foreach ($rows as $row) {
            $citizenId = trim((string) ($row['citizen_id'] ?? ''));

            // ไม่มีเลขบัตร = ข้อมูลไม่ครบ ใช้เป็นกุญแจไม่ได้
            if ($citizenId === '') {
                if (count($warnings) < self::MAX_ROW_ERRORS) {
                    $warnings[] = 'ข้ามแถวลำดับที่ ' . ($row['seq_number'] ?? '?')
                        . ' — ไม่พบเลขบัตรประชาชน';
                }

                continue;
            }

            // เทียบงวด: เดือน/ปีมากที่สุด = ข้อมูลล่าสุด
            $key = ($row['fiscal_year'] ?? 0) * 100 + ($row['period_month'] ?? 0);

            if (! isset($latest[$citizenId]) || $latest[$citizenId]['_period_key'] < $key) {
                $latest[$citizenId] = $row + ['_period_key' => $key];
            }
        }

        if ($rows !== [] && $latest === []) {
            $warnings[] = 'ไม่พบเลขบัตรประชาชนเลย — ไฟล์นี้อาจไม่ใช่ไฟล์เงินเดือนรูปแบบใหม่';
        }

        return [
            'data' => array_values($latest),
            'warnings' => $warnings,
        ];
    }

    /**
     * preview 10 คนแรก พร้อมบอกว่าจะเพิ่มใหม่หรืออัปเดต
     *
     * @param  array<int, array<string, mixed>>  $data
     * @return array<string, mixed>
     */
    public function preview(array $data, array $warnings = []): array
    {
        $existingIds = Employee::whereIn('citizen_id', array_column($data, 'citizen_id'))
            ->pluck('citizen_id')
            ->flip();

        $rows = [];

        foreach (array_slice($data, 0, 10) as $row) {
            $rows[] = [
                'seq_number' => $row['seq_number'] ?? null,
                'period_month' => $row['period_month'] ?? null,
                'prefix' => $row['prefix'] ?? null,
                'first_name' => $row['first_name'] ?? null,
                'last_name' => $row['last_name'] ?? null,
                'employee_type' => $row['employee_type'] ?? null,
                'position_name' => $row['position_name'] ?? null,
                'position_number' => $row['position_number'] ?? null,
                'citizen_id' => $row['citizen_id'] ?? null,
                'bank_account' => $row['bank_account'] ?? null,
                'latest_salary' => $row['salary'] ?? null,
                'action' => isset($existingIds[$row['citizen_id']]) ? 'update' : 'insert',
            ];
        }

        return [
            'preview' => [
                'rows' => $rows,
                'total_rows' => count($data),
                'new_count' => count($data) - $existingIds->count(),
                'update_count' => $existingIds->count(),
            ],
            'warnings' => $warnings,
            'warning_count' => count($warnings),
        ];
    }

    /**
     * เขียนลงทะเบียนบุคลากร
     *
     * @param  array<int, array<string, mixed>>  $data
     */
    public function import(array $data, string $fileName, string $filePath, int $userId): Import
    {
        $import = Import::create([
            'import_type' => 'hr',
            'file_name'   => $fileName,
            'file_path'   => $filePath,
            'file_type'   => strtolower(pathinfo($fileName, PATHINFO_EXTENSION)),
            'uploaded_by' => $userId,
            'status'      => 'processing',
        ]);

        $rowErrors = [];
        $inserted = 0;
        $updated = 0;

        try {
            DB::beginTransaction();

            $prefixMap = $this->loadLookupMap(Prefix::class, 'name');
            $typeMap = $this->loadLookupMap(EmployeeType::class, 'name');

            foreach ($data as $row) {
                $citizenId = trim((string) $row['citizen_id']);

                // ตำแหน่ง — สร้างใหม่ถ้ายังไม่มีในทะเบียน
                $positionId = $this->resolvePositionId($row['position_name'] ?? null);

                $attributes = [
                    'prefix_id'          => $this->lookupId($prefixMap, $row['prefix'] ?? null),
                    'first_name'         => trim((string) ($row['first_name'] ?? '')),
                    'last_name'          => trim((string) ($row['last_name'] ?? '')),
                    'employee_type_id'   => $this->lookupId($typeMap, $row['employee_type'] ?? null),
                    'position_id'        => $positionId,
                    'position_number'    => $row['position_number'] ?? null,
                    'bank_account'       => $row['bank_account'] ?? null,
                    'latest_salary'      => $row['salary'] ?? null,
                    'latest_period_year' => $row['fiscal_year'] ?? null,
                    'latest_period_month' => $row['period_month'] ?? null,
                    'updated_by'         => $userId,
                ];

                $employee = Employee::where('citizen_id', $citizenId)->first();

                if ($employee) {
                    $employee->fill($attributes)->save();
                    $updated++;
                } else {
                    Employee::create($attributes + [
                        'citizen_id' => $citizenId,
                        'created_by' => $userId,
                    ]);
                    $inserted++;
                }
            }

            DB::commit();

            $import->update([
                'total_rows'    => $inserted + $updated,
                'success_rows'  => $inserted + $updated,
                'inserted_rows' => $inserted,
                'updated_rows'  => $updated,
                'status'        => 'completed',
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            $import->update([
                'status'        => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            Log::error('New format employee import failed', ['error' => $e->getMessage()]);

            throw $e;
        }

        if ($rowErrors !== []) {
            DB::table('imports')->where('id', $import->id)
                ->update(['row_errors' => json_encode($rowErrors, JSON_UNESCAPED_UNICODE)]);
        }

        return $import->fresh();
    }

    /**
     * แผนที่ชื่อ → id ของตารางอ้างอิง
     *
     * @param  class-string  $model
     * @return array<string, int>
     */
    protected function loadLookupMap(string $model, string $column): array
    {
        /** @var \Illuminate\Database\Eloquent\Model $instance */
        $instance = new $model();

        return $instance->newQuery()
            ->pluck($instance->getKeyName(), $column)
            ->map(fn ($id) => (int) $id)
            ->toArray();
    }

    /**
     * หา id จากชื่อ โดยยุบช่องว่างซ้ำก่อนเทียบ (ไฟล์จริงมีชื่อไม่สม่ำเสมอ)
     *
     * @param  array<string, int>  $map
     */
    protected function lookupId(array $map, ?string $name): ?int
    {
        $name = trim((string) $name);

        if ($name === '') {
            return null;
        }

        return $map[$name] ?? $map[preg_replace('/\s+/u', ' ', $name)] ?? null;
    }

    /**
     * หา position_id จากชื่อตำแหน่ง — สร้างใหม่ถ้ายังไม่มี
     */
    protected function resolvePositionId(?string $name): ?int
    {
        $name = trim((string) $name);

        if ($name === '') {
            return null;
        }

        $existing = DB::table('positions')->where('name', $name)->value('id');

        return $existing !== null
            ? (int) $existing
            : DB::table('positions')->insertGetId(['name' => $name]);
    }
}