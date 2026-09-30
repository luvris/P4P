<?php

namespace App\Services;

use App\Models\Duty;
use App\Models\Employee;
use App\Models\Group;
use App\Models\Import;
use App\Models\Work;
use App\Services\Parsers\DutyAssignmentXlsxParser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Service สำหรับ "นำเข้าข้อมูลการอยู่ภารกิจของบุคลากร"
 *
 * แยกออกจาก HrImportService ทั้งหมด — อัปเดตเฉพาะคอลัมน์ duty_id / group_id / work_id
 * ของ employees โดยเชื่อมบุคลากรผ่าน PID (employees.employee_id)
 */
class DutyAssignmentImportService
{
    public const IMPORT_TYPE = 'duty_assignment';

    public function __construct(
        protected DutyAssignmentXlsxParser $parser
    ) {}

    /**
     * อ่านไฟล์เป็น array ของแถว
     */
    public function parse(string $filePath): array
    {
        return $this->parser->parse($filePath);
    }

    /**
     * คอลัมน์ที่ระบบต้องการแต่ไม่พบในไฟล์
     */
    public function missingColumns(string $filePath): array
    {
        return $this->parser->missingColumns($filePath);
    }

    /**
     * ตรวจสอบข้อมูลก่อนนำเข้า (ไม่เขียนฐานข้อมูล) — คืน 10 แถวแรก + คำเตือนทั้งหมด
     */
    public function preview(array $rows): array
    {
        $lookups = $this->loadLookups();

        $preview = [];
        $warnings = [];
        $matched = 0;

        foreach ($rows as $row) {
            $resolved = $this->resolveRow($row, $lookups);

            if (count($preview) < 10) {
                $preview[] = [
                    'row'       => $row['row'],
                    'pid'       => $row['pid'],
                    'duty'      => $row['duty'],
                    'group'     => $row['group'],
                    'work'      => $row['work'],
                    'full_name' => $resolved['employee']?->full_name,
                    'status'    => $resolved['errors'] === [] ? 'ok' : 'error',
                ];
            }

            if ($resolved['errors'] === []) {
                $matched++;
            }

            foreach ($resolved['errors'] as $message) {
                $warnings[] = ['row' => $row['row'], 'message' => $message];
            }

            foreach ($resolved['warnings'] as $message) {
                $warnings[] = ['row' => $row['row'], 'message' => $message];
            }
        }

        return [
            'preview' => [
                'rows'         => $preview,
                'total_rows'   => count($rows),
                'matched_rows' => $matched,
            ],
            'warnings'      => $warnings,
            'warning_count' => count($warnings),
        ];
    }

    /**
     * นำเข้าข้อมูลจริง — อัปเดต employees.duty_id / group_id / work_id
     */
    public function import(array $rows, string $fileName, string $filePath, int $userId): array
    {
        $import = Import::create([
            'import_type' => self::IMPORT_TYPE,
            'file_name'   => $fileName,
            'file_path'   => $filePath,
            'file_type'   => strtolower(pathinfo($fileName, PATHINFO_EXTENSION)),
            'uploaded_by' => $userId,
            'status'      => 'processing',
        ]);

        $updated = 0;
        $unchanged = 0;
        $skipped = 0;
        $errors = 0;
        $rowErrors = [];

        try {
            DB::beginTransaction();

            $lookups = $this->loadLookups();

            foreach ($rows as $row) {
                try {
                    $resolved = $this->resolveRow($row, $lookups);

                    if ($resolved['errors'] !== []) {
                        $skipped++;
                        foreach ($resolved['errors'] as $message) {
                            $rowErrors[] = ['row' => $row['row'], 'error' => $message];
                        }
                        continue;
                    }

                    /** @var Employee $employee */
                    $employee = $resolved['employee'];

                    // คำเตือนระดับแถว (กลุ่มงาน/งานจับคู่ไม่ได้) — ยังนำเข้าต่อ
                    foreach ($resolved['warnings'] as $message) {
                        $rowErrors[] = ['row' => $row['row'], 'error' => $message];
                    }

                    $payload = [
                        'duty_id'  => $resolved['duty_id'],
                        'group_id' => $resolved['group_id'],
                        'work_id'  => $resolved['work_id'],
                    ];

                    $isDirty = (int) $employee->duty_id !== (int) $payload['duty_id']
                        || (int) $employee->group_id !== (int) $payload['group_id']
                        || (int) $employee->work_id !== (int) $payload['work_id'];

                    if (! $isDirty) {
                        $unchanged++;
                        continue;
                    }

                    $employee->fill($payload);
                    $employee->updated_by = $userId;
                    $employee->save();

                    $updated++;
                } catch (\Exception $e) {
                    $errors++;
                    $rowErrors[] = ['row' => $row['row'] ?? null, 'error' => $e->getMessage()];
                    Log::error('Duty assignment import row failed: ' . $e->getMessage(), ['row' => $row]);
                }
            }

            DB::commit();

            $import->update([
                'total_rows'   => count($rows),
                'success_rows' => $updated + $unchanged,
                'updated_rows' => $updated,
                'skipped_rows' => $skipped,
                'error_rows'   => $errors,
                'status'       => 'completed',
            ]);

            // row_errors ไม่อยู่ใน $fillable ของ Import (ไม่แก้ model เดิม)
            // จึงเขียนตรงผ่าน query builder เฉพาะ import แถวนี้
            if ($rowErrors !== []) {
                DB::table('imports')
                    ->where('id', $import->id)
                    ->update(['row_errors' => json_encode($rowErrors, JSON_UNESCAPED_UNICODE)]);
            }

            return [
                'import'  => $import->fresh(),
                'summary' => [
                    'total'     => count($rows),
                    'updated'   => $updated,
                    'unchanged' => $unchanged,
                    'skipped'   => $skipped,
                    'errors'    => $errors,
                ],
                'row_errors'    => $rowErrors,
                'warning_count' => count($rowErrors),
            ];
        } catch (\Exception $e) {
            DB::rollBack();

            $import->update([
                'status'        => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * โหลด lookup ภารกิจ/กลุ่มงาน/งาน มาไว้ใน memory
     */
    protected function loadLookups(): array
    {
        return [
            'duties' => Duty::pluck('id', 'name')->toArray(),
            'groups' => Group::get(['id', 'name', 'duty_id']),
            'works'  => Work::get(['id', 'name', 'group_id']),
        ];
    }

    /**
     * แปลงชื่อภารกิจ/กลุ่มงาน/งาน เป็น id พร้อมตรวจสอบความถูกต้อง
     *
     * @return array{employee: ?Employee, duty_id: ?int, group_id: ?int, work_id: ?int, errors: array<int, string>, warnings: array<int, string>}
     */
    protected function resolveRow(array $row, array $lookups): array
    {
        $errors = [];
        $warnings = [];
        $employee = null;
        $dutyId = null;
        $groupId = null;
        $workId = null;

        $pid = $row['pid'] ?? null;

        if ($pid === null) {
            $errors[] = 'ไม่พบ PID ในแถวนี้';
        } else {
            $employee = Employee::where('employee_id', $pid)->first();

            if (! $employee) {
                $errors[] = "ไม่พบบุคลากรที่มี PID = {$pid}";
            }
        }

        // ภารกิจ (จำเป็น — ถ้าไม่ตรงถือว่าแถวนี้ใช้ไม่ได้)
        if (! empty($row['duty'])) {
            $dutyId = $this->matchByName($lookups['duties'], $row['duty']);
            if ($dutyId === null) {
                $errors[] = "ไม่พบภารกิจชื่อ \"{$row['duty']}\" ในระบบ";
            }
        } else {
            $errors[] = 'ไม่ได้ระบุภารกิจ';
        }

        // กลุ่มงาน (ไม่บังคับ — ถ้าจับคู่ไม่ได้จะเตือนแต่ยังนำเข้าภารกิจได้)
        if (! empty($row['group'])) {
            $group = $lookups['groups']->first(function ($item) use ($row, $dutyId) {
                if ($this->normalizeName($item->name) !== $this->normalizeName($row['group'])) {
                    return false;
                }

                return $dutyId === null || $item->duty_id === null || (int) $item->duty_id === (int) $dutyId;
            });

            if (! $group) {
                $warnings[] = "ไม่พบกลุ่มงานชื่อ \"{$row['group']}\" ภายใต้ภารกิจที่ระบุ (ข้ามเฉพาะกลุ่มงาน)";
            } else {
                $groupId = (int) $group->id;
            }
        }

        // งาน (ไม่บังคับ — ถ้าจับคู่ไม่ได้จะเตือนแต่ยังนำเข้าภารกิจ/กลุ่มงานได้)
        if (! empty($row['work'])) {
            $work = $lookups['works']->first(function ($item) use ($row, $groupId) {
                if ($this->normalizeName($item->name) !== $this->normalizeName($row['work'])) {
                    return false;
                }

                return $groupId === null || $item->group_id === null || (int) $item->group_id === (int) $groupId;
            });

            if (! $work) {
                $warnings[] = "ไม่พบงานชื่อ \"{$row['work']}\" ภายใต้กลุ่มงานที่ระบุ (ข้ามเฉพาะงาน)";
            } else {
                $workId = (int) $work->id;
            }
        }

        return [
            'employee' => $employee,
            'duty_id'  => $dutyId,
            'group_id' => $groupId,
            'work_id'  => $workId,
            'errors'   => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * จับคู่ชื่อกับ map [name => id] แบบไม่สนช่องว่าง/ตัวพิมพ์
     */
    protected function matchByName(array $map, string $name): ?int
    {
        if (isset($map[$name])) {
            return (int) $map[$name];
        }

        $target = $this->normalizeName($name);
        foreach ($map as $key => $id) {
            if ($this->normalizeName((string) $key) === $target) {
                return (int) $id;
            }
        }

        return null;
    }

    protected function normalizeName(?string $name): string
    {
        $name = trim((string) $name);

        // ตัดรหัสนำหน้าในไฟล์ Excel เช่น "59_กลุ่มงานการพยาบาลผู้ป่วยนอก" → "กลุ่มงานการพยาบาลผู้ป่วยนอก"
        $name = preg_replace('/^\s*\d+\s*[_\-.]\s*/u', '', $name);

        $name = mb_strtolower($name);
        $name = preg_replace('/\s+/u', '', $name);

        // ตัดคำนำหน้า "ภารกิจ" ที่ไฟล์ใส่มาแต่ฐานข้อมูลไม่มี
        // เช่น "ภารกิจด้านการพยาบาล" → "ด้านการพยาบาล"
        if (mb_strlen($name) > mb_strlen('ภารกิจ') && str_starts_with($name, 'ภารกิจ')) {
            $name = mb_substr($name, mb_strlen('ภารกิจ'));
        }

        return $name;
    }
}
