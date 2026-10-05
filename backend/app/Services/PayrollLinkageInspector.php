<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Prefix;

/**
 * ตรวจว่าแถว payroll ผูกกับทะเบียนบุคลากรได้หรือไม่
 *
 * ถ้าเลขบัตรประชาชนไม่ตรง ระบบจะผูกไม่ได้อย่างเงียบ ๆ ทำให้คนนั้น
 * ถูกตัดออกจากฐานเงินสำรอง และไปกองที่ "ไม่ระบุภารกิจ"
 * ตัวนี้จะหาว่าแถวนั้นน่าจะเป็นใคร แล้วคืนคำเตือนให้ผู้ใช้ตรวจสอบ
 *
 * เงื่อนไขที่เตือน
 *  1) ชื่อ-นามสกุลตรงกับบุคลากรที่ยัง "ปฏิบัติงานอยู่" แต่เลขบัตรไม่ตรง
 *  2) เลขบัตรประชาชนใกล้เคียงกับบุคลากรคนใดคนหนึ่ง (ห่างกันไม่เกิน NEAR_DISTANCE หลัก)
 */
class PayrollLinkageInspector
{
    /** ระยะห่างของเลขบัตรที่ยังถือว่า "ใกล้เคียง" */
    protected const NEAR_DISTANCE = 2;

    /** สถานะที่ถือว่ายังทำงานอยู่ */
    protected const ACTIVE_STATUS = 'ปฏิบัติงานอยู่';

    /** เพดานจำนวนบุคลากรที่สแกนหาเลขใกล้เคียง กันข้อมูลโตแล้วช้า */
    protected const POOL_LIMIT = 5000;

    /**
     * เติมเลขบัตรประชาชนให้แถวที่ไม่มี โดยหาจากทะเบียนบุคลากรด้วยชื่อ-นามสกุล
     *
     * ใช้กับไฟล์ payroll รูปแบบใหม่ที่ไม่มีคอลัมน์เลขบัตรประชาชนเลย —
     * ถ้าไม่ derive ทุกแถวจะถูกถือเป็น "แถวรวมยอด" และถูกข้ามทั้งไฟล์
     *
     * เติมเฉพาะเมื่อชื่อ-นามสกุล match บุคลากรในทะเบียนตัวเดียวพอดี
     * (match หลายคน = ชื่อซ้ำ จะเดาไม่ได้ จึงปล่อยว่างเพื่อไม่ผูกเลขบัตรผิดคน)
     *
     * @param  array<int, array>  $rows  แก้ในที่ (by reference) แล้วคืนออกมา
     * @return array<int, array>
     */
    public function deriveCitizenIdsByName(array $rows): array
    {
        $hasAnyCitizenId = false;

        foreach ($rows as $row) {
            if (trim((string) ($row['citizen_id'] ?? '')) !== '') {
                $hasAnyCitizenId = true;
                break;
            }
        }

        // ไฟล์มีคอลัมน์เลขบัตรอยู่แล้ว → ไม่ต้อง derive (กันเติมทับเลขที่ไฟล์ให้มา)
        if ($hasAnyCitizenId) {
            return $rows;
        }

        $prefixes = $this->knownPrefixes(Employee::with('prefix:id,name')->limit(self::POOL_LIMIT)->get());

        $byName = [];

        Employee::query()
            ->whereNotNull('citizen_id')
            ->where('citizen_id', '!=', '')
            ->limit(self::POOL_LIMIT)
            ->get()
            ->each(function ($employee) use (&$byName, $prefixes) {
                $nameKey = $this->nameKey($employee->first_name, $employee->last_name, $prefixes);

                if ($nameKey !== '') {
                    $byName[$nameKey][] = $this->digits($employee->citizen_id);
                }
            });

        foreach ($rows as $index => $row) {
            if (trim((string) ($row['citizen_id'] ?? '')) !== '') {
                continue;
            }

            $nameKey = $this->nameKey($row['first_name'] ?? null, $row['last_name'] ?? null, $prefixes);

            if ($nameKey === '') {
                continue;
            }

            $matches = array_unique($byName[$nameKey] ?? []);

            if (count($matches) === 1) {
                $rows[$index]['citizen_id'] = $matches[0];
            }
        }
        
        return $rows;
    }

    /**
     * @param  array<int, array>  $rows  แถว payroll ที่มีเลขบัตรประชาชนแล้ว
     * @return array<int, array>  คำเตือน [{row, type, reason, error, ...}]
     */
    public function inspect(array $rows): array
    {
        $employees = Employee::with(['prefix:id,name', 'status:id,name'])
            ->limit(self::POOL_LIMIT)
            ->get();

        if ($employees->isEmpty()) {
            return [];
        }

        $prefixes = $this->knownPrefixes($employees);

        $byCitizenId = [];
        $byName = [];

        foreach ($employees as $employee) {
            $citizenId = $this->digits($employee->citizen_id);
            if ($citizenId !== '') {
                // เก็บเลขบัตรเป็น string ไว้ในค่า เพราะ PHP จะแปลงคีย์ที่เป็นตัวเลขเป็น int
                // ทำให้ศูนย์นำหน้าหายไปจากข้อความเตือน
                $byCitizenId[$citizenId] = [
                    'citizen_id' => $citizenId,
                    'employee'   => $employee,
                ];
            }

            $nameKey = $this->nameKey($employee->first_name, $employee->last_name, $prefixes);
            if ($nameKey !== '') {
                $byName[$nameKey][] = $employee;
            }
        }

        $warnings = [];
        $seen = [];

        foreach ($rows as $index => $row) {
            $citizenId = $this->digits($row['citizen_id'] ?? null);

            // ไม่มีเลขบัตร หรือผูกกับทะเบียนได้ตรงเป๊ะ → ไม่ต้องเตือน
            if ($citizenId === '' || isset($byCitizenId[$citizenId])) {
                continue;
            }

            $nameKey = $this->nameKey($row['first_name'] ?? null, $row['last_name'] ?? null, $prefixes);
            $dedupeKey = $citizenId . '|' . $nameKey;

            if (isset($seen[$dedupeKey])) {
                continue;
            }
            $seen[$dedupeKey] = true;

            $warning = $this->buildWarning($index, $citizenId, $nameKey, $byName, $byCitizenId);

            if ($warning !== null) {
                $warnings[] = $warning;
            }
        }

        return $warnings;
    }

    /**
     * สร้างคำเตือนของแถวหนึ่ง ๆ (คืน null ถ้าไม่เข้าเงื่อนไขใดเลย)
     */
    protected function buildWarning(
        int $index,
        string $citizenId,
        string $nameKey,
        array $byName,
        array $byCitizenId,
    ): ?array {
        $rowNumber = $index + 2; // +2 = เผื่อ header row และ index เริ่มที่ 0

        // 1) ชื่อตรงกับบุคลากรในทะเบียน
        $sameName = $nameKey === '' ? [] : ($byName[$nameKey] ?? []);

        // รู้แล้วว่าเป็นใคร แต่ไม่ได้ปฏิบัติงานอยู่ (ลาออก/ลาศึกษาต่อ) → ไม่ต้องเตือน
        if ($sameName !== [] && ! $this->hasActiveStatus($sameName)) {
            return null;
        }

        $active = array_values(array_filter(
            $sameName,
            fn ($employee) => $employee->status?->name === self::ACTIVE_STATUS,
        ));

        if ($active !== []) {
            $employee = $active[0];
            $registryId = $this->digits($employee->citizen_id);

            return [
                'row'                 => $rowNumber,
                'type'                => 'link',
                'reason'              => 'name_match',
                'error'               => sprintf(
                    'ชื่อ %s ตรงกับบุคลากรที่ปฏิบัติงานอยู่ แต่เลขบัตรประชาชนไม่ตรงกัน (ในไฟล์: %s / ทะเบียน: %s)',
                    $employee->full_name,
                    $citizenId,
                    $registryId !== '' ? $registryId : '-',
                ),
                'employee_id'         => $employee->id,
                'employee_name'       => $employee->full_name,
                'employee_citizen_id' => $registryId !== '' ? $registryId : null,
                'file_citizen_id'     => $citizenId,
            ];
        }

        // 2) เลขบัตรประชาชนใกล้เคียงกับบุคลากรคนใดคนหนึ่ง
        $closest = null;

        foreach ($byCitizenId as $entry) {
            $distance = $this->citizenIdDistance($citizenId, $entry['citizen_id']);

            if ($closest === null || $distance < $closest['distance']) {
                $closest = [
                    'employee'   => $entry['employee'],
                    'citizen_id' => $entry['citizen_id'],
                    'distance'   => $distance,
                ];
            }

            if ($distance === 0) {
                break;
            }
        }

        if ($closest === null || $closest['distance'] > self::NEAR_DISTANCE) {
            return null;
        }

        return [
            'row'                 => $rowNumber,
            'type'                => 'link',
            'reason'              => 'near_citizen_id',
            'error'               => sprintf(
                'เลขบัตรประชาชน %s ไม่ตรงกับทะเบียน — ใกล้เคียงกับ %s (%s) ห่างกัน %d หลัก กรุณาตรวจสอบ',
                $citizenId,
                $closest['employee']->full_name,
                $closest['citizen_id'],
                $closest['distance'],
            ),
            'employee_id'         => $closest['employee']->id,
            'employee_name'       => $closest['employee']->full_name,
            'employee_citizen_id' => $closest['citizen_id'],
            'file_citizen_id'     => $citizenId,
        ];
    }

    /**
     * ในกลุ่มบุคลากรที่ชื่อตรงกัน มีคนที่ยังปฏิบัติงานอยู่หรือไม่
     */
    protected function hasActiveStatus(array $employees): bool
    {
        foreach ($employees as $employee) {
            if ($employee->status?->name === self::ACTIVE_STATUS) {
                return true;
            }
        }

        return false;
    }

    /**
     * คำนำหน้าที่ต้องตัดออกก่อนเทียบชื่อ
     *
     * ทะเบียนบุคลากรแยกคำนำหน้าไว้ที่ prefix_id แต่ไฟล์ payroll ใส่มาพร้อมชื่อ
     * จึงต้องตัดออกทั้งสองฝั่งก่อนเทียบ
     *
     * @return array<int, string>
     */
    protected function knownPrefixes($employees): array
    {
        $prefixes = Prefix::query()->get(['name', 'short_name'])
            ->flatMap(fn ($prefix) => [$prefix->name, $prefix->short_name])
            ->filter()
            ->all();

        // กันกรณี prefix ในไฟล์ไม่ถูกบันทึกไว้ในตาราง prefixes
        $prefixes = array_merge($prefixes, ['นางสาว', 'นาง', 'นาย', 'ด.ช.', 'ด.ญ.', 'เด็กชาย', 'เด็กหญิง']);

        foreach ($employees as $employee) {
            if ($employee->prefix?->name) {
                $prefixes[] = $employee->prefix->name;
            }
        }

        $prefixes = array_values(array_unique(array_filter($prefixes)));

        // ตัดคำที่ยาวก่อน เพื่อไม่ให้ "นาง" ถูกตัดก่อน "นางสาว"
        usort($prefixes, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));

        return $prefixes;
    }

    /**
     * คีย์เทียบชื่อ = ชื่อ + นามสกุล ที่ตัดคำนำหน้าและช่องว่างออกแล้ว
     */
    protected function nameKey(?string $firstName, ?string $lastName, array $prefixes): string
    {
        $first = $this->stripPrefix($firstName, $prefixes);
        $last = $this->stripSpaces($lastName);

        if ($first === '' && $last === '') {
            return '';
        }

        return $first . '|' . $last;
    }

    protected function stripPrefix(?string $value, array $prefixes): string
    {
        $value = $this->stripSpaces($value);
        if ($value === '') {
            return '';
        }

        foreach ($prefixes as $prefix) {
            if ($prefix !== '' && mb_strpos($value, $prefix) === 0) {
                return mb_substr($value, mb_strlen($prefix));
            }
        }

        return $value;
    }

    protected function stripSpaces(?string $value): string
    {
        return preg_replace('/\s+/u', '', (string) $value) ?? '';
    }

    /**
     * เอาเฉพาะตัวเลขจากเลขบัตรประชาชน
     */
    protected function digits(?string $value): string
    {
        return preg_replace('/\D+/', '', (string) $value) ?? '';
    }

    /**
     * ระยะห่างระหว่างเลขบัตรสองค่า (เทียบแบบไม่สนใจศูนย์นำหน้า)
     */
    protected function citizenIdDistance(string $a, string $b): int
    {
        return min(
            levenshtein($a, $b),
            levenshtein(ltrim($a, '0') ?: '0', ltrim($b, '0') ?: '0'),
        );
    }
}
