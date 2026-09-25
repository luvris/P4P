<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmploymentHistory;
use App\Models\Import;
use App\Models\Prefix;
use App\Models\EmployeeStatus;
use App\Services\Parsers\HrXlsxParser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class HrImportService
{
    /**
     * Parse ไฟล์เป็น array แยกเป็น 2 ชุด: employees และ employments
     */
    public function parse(string $filePath, string $fileName): array
    {
        $extension = pathinfo($fileName, PATHINFO_EXTENSION);
        $parser = new HrXlsxParser();
        
        return $parser->parse($filePath);
    }

    /**
     * สร้าง preview 10 แถวแรก
     */
    public function preview(array $parsedData): array
    {
        $employees = array_slice($parsedData['employees'] ?? [], 0, 10);
        $employments = array_slice($parsedData['employments'] ?? [], 0, 10);
        
        $warnings = [];
        $warningCount = 0;
        
        // ตรวจสอบคำเตือนเบื้องต้น
        if (empty($parsedData['employees'])) {
            $warnings[] = 'ไม่พบข้อมูลพนักงานในไฟล์';
            $warningCount++;
        }
        
        return [
            'preview' => [
                'employees'   => $employees,
                'employments' => $employments,
                'employee_count'   => count($parsedData['employees'] ?? []),
                'employment_count' => count($parsedData['employments'] ?? []),
            ],
            'warnings' => $warnings,
            'warning_count' => $warningCount,
        ];
    }

    /**
     * Import ข้อมูล HR ทั้ง 2 ชุด
     */
    public function import(array $parsedData, string $fileName, string $filePath, int $userId): array
    {
        $import = Import::create([
            'import_type' => 'hr',
            'file_name'   => $fileName,
            'file_path'   => $filePath,
            'file_type'   => strtolower(pathinfo($fileName, PATHINFO_EXTENSION)),
            'uploaded_by' => $userId,
            'status'      => 'processing',
        ]);

        $employeeInserted = 0;
        $employeeUpdated = 0;
        $employeeSkipped = 0;
        $employeeErrors = 0;
        
        $employmentInserted = 0;
        $employmentSkipped = 0;
        $employmentErrors = 0;
        
        $rowErrors = [];

        try {
            DB::beginTransaction();

            // 1. Import ข้อมูล Employees ก่อน
            if (!empty($parsedData['employees'])) {
                $result = $this->importEmployees($parsedData['employees'], $userId);
                $employeeInserted = $result['inserted'];
                $employeeUpdated = $result['updated'];
                $employeeSkipped = $result['skipped'];
                $employeeErrors = $result['errors'];
                $rowErrors = array_merge($rowErrors, $result['row_errors']);
            }

            // 2. Import ข้อมูล Employment Histories
            if (!empty($parsedData['employments'])) {
                $result = $this->importEmploymentHistories($parsedData['employments'], $userId);
                $employmentInserted = $result['inserted'];
                $employmentSkipped = $result['skipped'];
                $employmentErrors = $result['errors'];
                $rowErrors = array_merge($rowErrors, $result['row_errors']);
            }

            DB::commit();
            
            // อัพเดต employees จากประวัติล่าสุด (หลังจาก import เสร็จแล้ว)
            $this->updateEmployeesFromLatestHistory();

            $import->update([
                'total_rows'   => count($parsedData['employees'] ?? []) + count($parsedData['employments'] ?? []),
                'success_rows' => $employeeInserted + $employeeUpdated + $employmentInserted,
                'error_rows'   => $employeeErrors + $employmentErrors,
                'skipped_rows' => $employeeSkipped + $employmentSkipped,
                'row_errors'   => !empty($rowErrors) ? json_encode($rowErrors, JSON_UNESCAPED_UNICODE) : null,
                'status'       => 'completed',
            ]);

            return [
                'import'   => $import,
                'summary'  => [
                    'employees' => [
                        'inserted' => $employeeInserted,
                        'updated'  => $employeeUpdated,
                        'skipped'  => $employeeSkipped,
                        'errors'   => $employeeErrors,
                    ],
                    'employments' => [
                        'inserted' => $employmentInserted,
                        'skipped'  => $employmentSkipped,
                        'errors'   => $employmentErrors,
                    ],
                ],
                'warnings'      => $rowErrors,
                'warning_count' => count($rowErrors),
                'row_errors'    => $rowErrors,
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
     * Import ข้อมูล Employees
     */
    protected function importEmployees(array $employees, int $userId): array
    {
        $inserted = 0;
        $updated = 0;
        $skipped = 0;
        $errors = 0;
        $rowErrors = [];

        // Cache mappings
        $prefixMap = DB::table('prefixes')->pluck('id', 'name')->toArray();
        $employeeTypeMap = DB::table('employee_types')->pluck('id', 'name')->toArray();
        $positionMap = DB::table('positions')->pluck('id', 'name')->toArray();
        $statusMap = DB::table('employee_statuses')->pluck('id', 'name')->toArray();
        
        // Default values
        $defaultStatusId = DB::table('employee_statuses')->where('name', 'ปฏิบัติงานอยู่')->value('id');

        foreach ($employees as $index => $row) {
            try {
                // Validate citizen_id
                if (empty($row['citizen_id']) || strlen($row['citizen_id']) !== 13) {
                    $skipped++;
                    $rowErrors[] = "แถว " . ($index + 2) . " (Employee): เลขบัตรประชาชนไม่ถูกต้อง";
                    continue;
                }

                // แปลง prefix string เป็น prefix_id
                $prefixId = null;
                if (!empty($row['prefix'])) {
                    $prefixId = $prefixMap[$row['prefix']] ?? null;
                }

                // แปลง sex string เป็น enum ('M', 'F', 'O')
                $sex = null;
                if (!empty($row['sex'])) {
                    $sexInput = mb_strtolower(trim($row['sex']));
                    $sexMap = [
                        'ชาย' => 'M',
                        'หญิง' => 'F',
                        'male' => 'M',
                        'female' => 'F',
                        'm' => 'M',
                        'f' => 'F',
                        'o' => 'O',
                    ];
                    $sex = $sexMap[$sexInput] ?? null;
                }

                // ตัดข้อความที่ยาวเกินสำหรับ tel และ mobile (varchar 20)
                $tel = !empty($row['tel']) ? substr($row['tel'], 0, 20) : null;
                $mobile = !empty($row['mobile']) ? substr($row['mobile'], 0, 20) : null;
                
                // แปลง status string เป็น status_id
                $statusId = $defaultStatusId;
                if (!empty($row['status'])) {
                    // Status mapping
                    $statusMapping = [
                        'ทำงาน' => 'ปฏิบัติงานอยู่',
                        'active' => 'ปฏิบัติงานอยู่',
                        'ปฏิบัติงาน' => 'ปฏิบัติงานอยู่',
                        'ออกจากงาน' => 'ลาออก',
                        'resign' => 'ลาออก',
                        'retired' => 'ลาออก',
                        'เกษียณ' => 'ลาออก',
                    ];
                    
                    $statusName = trim($row['status']);
                    $mappedStatus = $statusMapping[mb_strtolower($statusName)] ?? $statusName;
                    
                    $statusId = $statusMap[$mappedStatus] ?? $statusMap[$statusName] ?? $defaultStatusId;
                }

                $data = [
                    'citizen_id'                => $row['citizen_id'] ?? null,
                    'employee_id'               => $row['employee_id'] ?? null,
                    'prefix_id'                 => $prefixId,
                    'first_name'                => $row['first_name'] ?? null,
                    'last_name'                 => $row['last_name'] ?? null,
                    'sex'                       => $sex,
                    'blood_type'                => $row['blood_type'] ?? null,
                    'birth_date'                => $row['birth_date'] ?? null,
                    'tel'                       => $tel,
                    'mobile'                    => $mobile,
                    'address1'                  => $row['address1'] ?? null,
                    'address2'                  => $row['address2'] ?? null,
                    'english_prefix'            => $row['english_prefix'] ?? null,
                    'english_first_name'        => $row['english_first_name'] ?? null,
                    'english_last_name'         => $row['english_last_name'] ?? null,
                    'email'                     => $row['email'] ?? null,
                    'line_token'                => $row['line_token'] ?? null,
                    'leaves_by'                 => $row['leaves_by'] ?? null,
                    'finger'                    => $row['finger'] ?? null,
                    'status_id'                 => $statusId,
                ];

                // กรองค่า null ออก
                $data = array_filter($data, fn($value) => $value !== null);

                // ใช้ updateOrCreate เพื่อป้องกันข้อมูลซ้ำ
                $employee = Employee::updateOrCreate(
                    ['citizen_id' => $row['citizen_id']],
                    $data
                );
                
                if ($employee->wasRecentlyCreated) {
                    $inserted++;
                } else {
                    $updated++;
                }
            } catch (\Exception $e) {
                $errors++;
                $rowErrors[] = "แถว " . ($index + 2) . " (Employee): " . $e->getMessage();
                Log::error("HR Import Employee Error: " . $e->getMessage(), ['row' => $row]);
            }
        }

        return [
            'inserted'   => $inserted,
            'updated'    => $updated,
            'skipped'    => $skipped,
            'errors'     => $errors,
            'row_errors' => $rowErrors,
        ];
    }

    /**
     * Import ข้อมูล Employment Histories
     */
    protected function importEmploymentHistories(array $employments, int $userId): array
    {
        $inserted = 0;
        $skipped = 0;
        $errors = 0;
        $rowErrors = [];
        
        // Cache mappings
        $employeeTypeMap = DB::table('employee_types')->pluck('id', 'name')->toArray();
        $positionMap = DB::table('positions')->pluck('id', 'name')->toArray();

        foreach ($employments as $index => $row) {
            try {
                // ใช้ employee_id (PID) เป็นตัวเชื่อมแทน citizen_id
                if (empty($row['employee_id'])) {
                    $skipped++;
                    $rowErrors[] = "แถว " . ($index + 2) . " (Employment): ไม่พบเลขไอดีพนักงาน (PID)";
                    continue;
                }
                
                // เช็ค serial_number ว่าง (เป็น unique key)
                if (empty($row['serial_number'])) {
                    $skipped++;
                    $rowErrors[] = "แถว " . ($index + 2) . " (Employment): ไม่พบเลขที่ (serial_number)";
                    continue;
                }

                // ค้นหา Employee จาก employee_id (PID จากไฟล์)
                // ต้องมี column employee_id ใน employees table ที่เก็บ PID ไว้
                $employee = Employee::where('employee_id', $row['employee_id'])->first();
                
                // ถ้าไม่เจอ ลองหาจาก citizen_id
                if (!$employee && !empty($row['employee_id'])) {
                    $employee = Employee::where('citizen_id', $row['employee_id'])->first();
                }
                
                if (!$employee) {
                    $skipped++;
                    $rowErrors[] = "แถว " . ($index + 2) . " (Employment): ไม่พบพนักงานที่มี PID = " . $row['employee_id'];
                    continue;
                }
                
                // แปลง employee_type (ประเภทบุคลากร) เป็น ID
                $employeeTypeId = null;
                if (!empty($row['employee_type'])) {
                    // เช็คใน cache ก่อน
                    if (isset($employeeTypeMap[$row['employee_type']])) {
                        $employeeTypeId = $employeeTypeMap[$row['employee_type']];
                    } else {
                        // Query หาจาก DB
                        $type = DB::table('employee_types')->where('name', $row['employee_type'])->first();
                        if ($type) {
                            $employeeTypeMap[$row['employee_type']] = $type->id;
                            $employeeTypeId = $type->id;
                        } else {
                            // สร้างใหม่
                            try {
                                $newTypeId = DB::table('employee_types')->insertGetId([
                                    'name' => $row['employee_type'],
                                    'sort_order' => 999,
                                    'created_at' => now(),
                                    'updated_at' => now(),
                                ]);
                                $employeeTypeMap[$row['employee_type']] = $newTypeId;
                                $employeeTypeId = $newTypeId;
                            } catch (\Exception $e) {
                                // Duplicate - query again
                                $type = DB::table('employee_types')->where('name', $row['employee_type'])->first();
                                if ($type) {
                                    $employeeTypeMap[$row['employee_type']] = $type->id;
                                    $employeeTypeId = $type->id;
                                }
                            }
                        }
                    }
                }
                
                // แปลง position (ตำแหน่ง) เป็น ID
                $positionId = null;
                if (!empty($row['position'])) {
                    // เช็คใน cache ก่อน
                    if (isset($positionMap[$row['position']])) {
                        $positionId = $positionMap[$row['position']];
                    } else {
                        // Query หาจาก DB
                        $position = DB::table('positions')->where('name', $row['position'])->first();
                        if ($position) {
                            $positionMap[$row['position']] = $position->id;
                            $positionId = $position->id;
                        } else {
                            // สร้างใหม่
                            try {
                                $newPositionId = DB::table('positions')->insertGetId([
                                    'name' => $row['position'],
                                    'created_at' => now(),
                                    'updated_at' => now(),
                                ]);
                                $positionMap[$row['position']] = $newPositionId;
                                $positionId = $newPositionId;
                            } catch (\Exception $e) {
                                // Duplicate - query again
                                $position = DB::table('positions')->where('name', $row['position'])->first();
                                if ($position) {
                                    $positionMap[$row['position']] = $position->id;
                                    $positionId = $position->id;
                                }
                            }
                        }
                    }
                }

                $data = [
                    'employee_id'      => $employee->id,
                    'serial_number'    => $row['serial_number'],
                    'wid'              => $row['wid'],
                    'employee_name'    => $row['employee_name'] ?? null,
                    'work_id'          => $row['work_id'],
                    'manager'          => $row['manager'],
                    'position'         => $row['position'],
                    'class'            => $row['class'],
                    'condition'        => $row['condition'],
                    'start_date'       => $row['start_date'],
                    'end_date'         => $row['end_date'],
                    'experience'       => $row['experience'],
                    'mark'             => $row['mark'],
                    'status'           => $row['status'] ?? null,  // เก็บ status จาก Excel
                    'payroll'          => $row['payroll'],
                    'appointment_date' => $row['appointment_date'],
                    'appointment_code' => $row['appointment_code'],
                ];

                // กรองค่า null ออก
                $data = array_filter($data, fn($value) => $value !== null);

                // ใช้ updateOrCreate เพื่อป้องกันข้อมูลซ้ำ
                // ใช้ employee_id + serial_number เป็น unique key
                $employment = EmploymentHistory::updateOrCreate(
                    [
                        'employee_id' => $employee->id,
                        'serial_number' => $row['serial_number'],
                    ],
                    $data
                );
                
                if ($employment->wasRecentlyCreated) {
                    $inserted++;
                } else {
                    // นับว่า updated แต่ไม่มี counter สำหรับ employment
                    $inserted++; // ยังคงนับเป็น inserted ตาม return structure เดิม
                }
            } catch (\Exception $e) {
                $errors++;
                $rowErrors[] = "แถว " . ($index + 2) . " (Employment): " . $e->getMessage();
                Log::error("HR Import Employment Error: " . $e->getMessage(), ['row' => $row]);
            }
        }

        return [
            'inserted'   => $inserted,
            'skipped'    => $skipped,
            'errors'     => $errors,
            'row_errors' => $rowErrors,
        ];
    }

    /**
     * อัพเดตข้อมูล employees จากประวัติล่าสุด
     * ลำดับความสำคัญ:
     * 1. experience = "ตำแหน่งปัจจุบัน"
     * 2. start_date ล่าสุด
     */
    protected function updateEmployeesFromLatestHistory()
    {
        $employees = Employee::all();
        $positionMap = DB::table('positions')->pluck('id', 'name')->toArray();
        $statusMap = DB::table('employee_statuses')->pluck('id', 'name')->toArray();
        
        // Status mapping
        $statusMapping = [
            'ทำงาน' => 'ปฏิบัติงานอยู่',
            'active' => 'ปฏิบัติงานอยู่',
            'ปฏิบัติงาน' => 'ปฏิบัติงานอยู่',
            'ออกจากงาน' => 'ลาออก',
            'resign' => 'ลาออก',
            'retired' => 'ลาออก',
            'เกษียณ' => 'ลาออก',
        ];
        
        foreach ($employees as $employee) {
            // หาประวัติปัจจุบันก่อน (experience = "ตำแหน่งปัจจุบัน")
            $currentHistory = EmploymentHistory::where('employee_id', $employee->id)
                ->where(function($q) {
                    $q->where('experience', 'LIKE', '%ตำแหน่งปัจจุบัน%')
                      ->orWhere('mark', 'LIKE', '%ตำแหน่งปัจจุบัน%');
                })
                ->orderBy('start_date', 'desc')
                ->first();
            
            // ถ้าไม่มี ให้ใช้ start_date ล่าสุด
            if (!$currentHistory) {
                $currentHistory = EmploymentHistory::where('employee_id', $employee->id)
                    ->whereNotNull('start_date')
                    ->orderBy('start_date', 'desc')
                    ->first();
            }
            
            if (!$currentHistory) {
                continue; // ไม่มีประวัติเลย
            }
            
            $updateData = [];
            
            // แปลง position
            if (!empty($currentHistory->position)) {
                // ใช้ firstOrCreate เพื่อหลีกเลี่ยง duplicate
                try {
                    $position = DB::table('positions')
                        ->where('name', $currentHistory->position)
                        ->first();
                    
                    if (!$position) {
                        $positionId = DB::table('positions')->insertGetId([
                            'name' => $currentHistory->position,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                        $positionMap[$currentHistory->position] = $positionId;
                    } else {
                        $positionId = $position->id;
                        $positionMap[$currentHistory->position] = $positionId;
                    }
                    
                    $updateData['position_id'] = $positionId;
                } catch (\Exception $e) {
                    // Ignore duplicate errors
                    if (!isset($positionMap[$currentHistory->position])) {
                        $position = DB::table('positions')->where('name', $currentHistory->position)->first();
                        if ($position) {
                            $positionMap[$currentHistory->position] = $position->id;
                            $updateData['position_id'] = $position->id;
                        }
                    }
                }
            }
            
            // แปลง status จาก employment_histories.status
            if (!empty($currentHistory->status)) {
                $statusName = trim($currentHistory->status);
                $mappedStatus = $statusMapping[mb_strtolower($statusName)] ?? $statusName;
                $statusId = $statusMap[$mappedStatus] ?? $statusMap[$statusName] ?? null;
                
                if ($statusId) {
                    $updateData['status_id'] = $statusId;
                }
            }
            
            if (!empty($updateData)) {
                $employee->update($updateData);
            }
        }
    }
}
