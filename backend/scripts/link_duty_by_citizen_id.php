<?php

/**
 * เชื่อมภารกิจ/ตำแหน่ง/ประเภท ของบุคลากร โดยอิงเลขบัตรประชาชน
 *
 * ไฟล์นำเข้าต้องมีหัวตาราง (หรือค่าคอลัมน์ตามลำดับ):
 *   รหัสประชาชน | ประเภท | ตำแหน่ง | ภารกิจ
 *
 * รองรับไฟล์ .tsv / .txt (คั่นด้วยแท็บ) และ .csv
 *
 * ใช้:
 *   php scripts/link_duty_by_citizen_id.php <ไฟล์> [--apply] [--all] [--skip-duty="ชื่อ"]
 *
 *   (ไม่มีตัวเลือก)  โหมดตรวจ — พิมพ์ผลลัพธ์ว่าจะเปลี่ยนอะไร แต่ยังไม่เขียนฐาน
 *   --apply         เขียนลงฐานข้อมูลจริง
 *   --all           เขียนครบทั้งภารกิจ ตำแหน่ง และประเภท
 *                   (ค่าเริ่มต้นเขียนเฉพาะภารกิจ เพราะสองคอลัมน์อื่นมาจากไฟล์เงินเดือนแล้ว)
 *   --skip-duty=X   ข้ามภารกิจชื่อนี้ (ใส่ซ้ำได้หลายครั้ง คั่นด้วยจุลภาค)
 *                   ใช้เมื่อยังไม่ตัดสินใจว่าจะเพิ่มภารกิจใหม่หรือไม่ — คนกลุ่มนั้นจะไม่ถูกแก้
 *
 * ⚠️ ห้ามรันบนเซิร์ฟเวอร์จริง — สคริปต์นี้เขียนข้อมูลบุคลากรโดยตรง
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$file = $argv[1] ?? null;
$apply = in_array('--apply', $argv, true);

if (! $file || ! is_file($file)) {
    fwrite(STDERR, "ใช้: php scripts/link_duty_by_citizen_id.php <ไฟล์ tsv/csv> [--apply] [--all]\n");
    exit(1);
}

// ค่าเริ่มต้นเขียนเฉพาะภารกิจ — ประเภท/ตำแหน่งมาจากไฟล์เงินเดือนอยู่แล้ว
$writeAll = in_array('--all', $argv, true);

$delimiter = str_ends_with(strtolower($file), '.csv') ? ',' : "\t";
$handle = fopen($file, 'r');

if ($handle === false) {
    fwrite(STDERR, "เปิดไฟล์ไม่ได้: {$file}\n");
    exit(1);
}

/** เก็บเฉพาะตัวเลขของเลขบัตรประชาชน (ไฟล์จริงเขียนเป็นกลุ่มละ 5 หลักคั่นด้วยช่องว่าง) */
$digits = function (?string $value): string {
    return preg_replace('/\D/', '', (string) $value);
};

/**
 * แยกคอลัมน์หนึ่งบรรทัด
 * - .csv ใช้ str_getcsv เพื่อรองรับ field ที่มีจุลภาคอยู่ในเครื่องหมายคำพูด (เช่น "ผู้อำนวยการเฉพาะด้าน (แพทย์) สูง")
 * - ไฟล์แท็บใช้ explode ตามปกติ
 */
$split = function (string $line) use ($delimiter): array {
    if ($delimiter === ',') {
        return str_getcsv(rtrim($line, "\r\n"));
    }

    return explode($delimiter, rtrim($line, "\r\n"));
};

/** ตัด BOM ของ UTF-8 (Excel มักใส่มาตอนบันทึก CSV ภาษาไทย) ออกจากเซลล์แรก */
$stripBom = function (string $value): string {
    return preg_replace('/^\xEF\xBB\xBF/', '', $value) ?? $value;
};

// อ่านหัวตารางเพื่อหาตำแหน่งคอลัมน์ — ทนต่อไฟล์ที่หัวตารางไม่ครบ
$header = (string) fgets($handle);
$columns = array_map('trim', $split($header));
$columns[0] = $stripBom($columns[0] ?? '');

$pick = function (array $candidates) use ($columns): ?int {
    foreach ($candidates as $candidate) {
        foreach ($columns as $i => $name) {
            if (mb_strtolower(trim($name)) === mb_strtolower($candidate)) {
                return $i;
            }
        }
    }

    return null;
};

// ภารกิจที่สั่งให้ข้าม — ใช้เมื่อชื่อนั้นยังไม่ควรถูกสร้างเป็นข้อมูลอ้างอิงใหม่
$skipDuties = [];
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--skip-duty=')) {
        foreach (explode(',', substr($arg, strlen('--skip-duty='))) as $name) {
            $name = trim($name);
            if ($name !== '') {
                $skipDuties[$name] = true;
            }
        }
    }
}

$idIndex = $pick(['รหัสประชาชน', 'เลขประจำตัวประชาชน', 'รหัสบัตรประชาชน', 'เลขบัตรประชาชน', 'บัตรประชาชน', 'id card', 'citizen_id']);
$typeIndex = $pick(['ประเภท', 'ประเภทบุคลากร', 'employee_type']);
$positionIndex = $pick(['ตำแหน่ง', 'position']);
$dutyIndex = $pick(['ภารกิจ', 'duty']);

if ($idIndex === null) {
    fwrite(STDERR, "ไม่พบคอลัมน์เลขบัตรประชาชนในไฟล์\n");
    exit(1);
}

if ($apply) {
    echo "โหมด: เขียนลงฐานข้อมูลจริง (--apply)\n";
} else {
    echo "โหมด: ตรวจสอบอย่างเดียว — ยังไม่เขียนอะไรลงฐานข้อมูล\n";
    echo "เมื่อตรวจผลแล้วพร้อม ให้รันซ้ำโดยเติม --apply\n";
}

echo $writeAll
    ? "ขอบเขต: ภารกิจ + ตำแหน่ง + ประเภท (--all)\n\n"
    : "ขอบเขต: เฉพาะภารกิจ\n\n";

/**
 * ทำความสะอาดชื่อภารกิจให้ตรงกับที่เก็บในตารางอ้างอิง
 * ไฟล์จริงเขียนว่า "ภารกิจด้านอำนวยการ" แต่ตาราง duties เก็บเป็น "ด้านอำนวยการ"
 * ถ้าไม่ตัด จะกลายเป็นสร้างชื่อซ้ำแยกชุดออกไป
 */
$normalizeDuty = function (string $value): string {
    $value = trim($value);
    $stripped = preg_replace('/^ภารกิจ\s*/u', '', $value);

    // ตัดแล้วเหลือคำว่า "ภารกิจ" เปล่า แปลว่าไม่มีคำอธิบายภารกิจ — ใช้ค่าเดิม
    return trim($stripped) === '' ? $value : trim($stripped);
};

/** หา id ของข้อมูลอ้างอิง ถ้าไม่มีให้สร้างใหม่ */
$resolve = function (string $table, string $name) use ($apply): ?int {
    $name = trim($name);
    if ($name === '') {
        return null;
    }

    $existing = DB::table($table)->where('name', $name)->value('id');

    if ($existing !== null) {
        return (int) $existing;
    }

    if (! $apply) {
        return null;   // โหมดตรวจ: บอกได้แค่ว่าจะต้องสร้างใหม่
    }

    return (int) DB::table($table)->insertGetId(['name' => $name]);
};

$report = [
    'matched'    => 0,
    'updated'    => 0,
    'no_id'      => 0,   // แถวที่ไม่มีเลขบัตร — จับคู่ไม่ได้
    'not_found'  => 0,   // ไม่พบคนนี้ในทะเบียน
    'no_duty'    => 0,   // จับคู่ได้แต่ไม่มีภารกิจในไฟล์
    'same_value' => 0,   // ตรงกับที่มีอยู่แล้ว ไม่ต้องแก้
    'skipped'    => 0,   // ภารกิจอยู่ในรายการข้าม (ยังไม่ตัดสินใจ)
];

$problems = [];
$newDuties = [];

while (($line = fgets($handle)) !== false) {
    // ข้ามเฉพาะบรรทัดที่ว่างจริง ๆ — บรรทัดที่มีแต่เว้นวรรคแต่ยังมีข้อมูล
    // (เช่นแถวที่ไม่มีเลขบัตรประชาชน) ต้องถูกนับและรายงาน ไม่ใช่ถูกข้ามเงียบ ๆ
    if (rtrim($line, "\r\n") === '') {
        continue;
    }

    $cells = $split($line);
    if (isset($cells[0])) {
        $cells[0] = $stripBom($cells[0]);
    }

    $citizenId = $digits($cells[$idIndex] ?? null);

    if ($citizenId === '') {
        $report['no_id']++;
        $problems[] = ['เลขบัตรประชาชน' => '(ว่าง)', 'เหตุผล' => 'ไม่มีเลขบัตรประชาชน'];
        continue;
    }

    $employee = DB::table('employees')->where('citizen_id', $citizenId)->first();

    if (! $employee) {
        $report['not_found']++;
        $problems[] = ['เลขบัตรประชาชน' => $citizenId, 'เหตุผล' => 'ไม่พบในทะเบียนบุคลากร'];
        continue;
    }

    $dutyName = $dutyIndex !== null ? $normalizeDuty($cells[$dutyIndex] ?? '') : '';
    $positionName = $writeAll && $positionIndex !== null ? trim($cells[$positionIndex] ?? '') : '';
    $typeName = $writeAll && $typeIndex !== null ? trim($cells[$typeIndex] ?? '') : '';

    if ($dutyName === '') {
        $report['no_duty']++;
        continue;
    }

    if (isset($skipDuties[$dutyName])) {
        $report['skipped']++;
        continue;
    }

    $report['matched']++;
    $changes = [];

    if ($dutyName !== '') {
        $dutyId = $resolve('duties', $dutyName);
        $dutyId ??= -1;   // โหมดตรวจ: ยังไม่ได้สร้าง = จะเป็น id ใหม่
        if ($dutyId === -1) {
            $newDuties[$dutyName] = true;
        }
        if ((int) $employee->duty_id !== $dutyId) {
            $changes['duty_id'] = $dutyId;
        }
    }

    if ($writeAll) {
        if ($positionName !== '') {
            $positionId = $resolve('positions', $positionName);
            $positionId ??= -1;
            if ((int) $employee->position_id !== $positionId) {
                $changes['position_id'] = $positionId;
            }
        }

        if ($typeName !== '') {
            $typeId = $resolve('employee_types', $typeName);
            $typeId ??= -1;
            if ((int) $employee->employee_type_id !== $typeId) {
                $changes['employee_type_id'] = $typeId;
            }
        }
    }

    if ($changes === []) {
        $report['same_value']++;
        continue;
    }

    $report['updated']++;

    if ($apply) {
        $changes['updated_at'] = now();
        DB::table('employees')->where('id', $employee->id)->update($changes);
    }
}

fclose($handle);

echo "———— สรุป ————\n";
echo "แถวที่จับคู่ได้:        {$report['matched']}\n";
echo "  • ต้องอัปเดต:          {$report['updated']}\n";
echo "  • ตรงกับข้อมูลเดิมแล้ว: {$report['same_value']}\n";
echo "ไม่พบในทะเบียน:           {$report['not_found']}\n";
echo "ไม่มีเลขบัตรประชาชน:      {$report['no_id']}\n";
echo "ไม่มีข้อมูลให้แก้:         {$report['no_duty']}\n";
echo "ข้ามตามที่สั่ง:             {$report['skipped']}\n";

if ($skipDuties !== []) {
    echo "\nภารกิจที่สั่งให้ข้าม:\n";
    foreach (array_keys($skipDuties) as $name) {
        echo "  • {$name}\n";
    }
}

if ($newDuties !== []) {
    echo "\nข้อมูลอ้างอิงที่ยังไม่มีในระบบ (จะถูกสร้างเมื่อใช้ --apply):\n";
    foreach (array_keys($newDuties) as $name) {
        echo "  • {$name}\n";
    }
}

if ($problems !== []) {
    $shown = array_slice($problems, 0, 20);
    echo "\n———— แถวที่จับคู่ไม่ได้ (แสดง " . count($shown) . ' จาก ' . count($problems) . " แถว) ————\n";
    foreach ($shown as $problem) {
        echo "  • {$problem['เลขบัตรประชาชน']} — {$problem['เหตุผล']}\n";
    }
    if (count($problems) > count($shown)) {
        echo '  ... และอีก ' . (count($problems) - count($shown)) . " แถว\n";
    }
}

echo $apply
    ? "\nเขียนลงฐานข้อมูลเรียบร้อย\n"
    : "\nยังไม่ได้เขียนอะไรลงฐานข้อมูล (โหมดตรวจ)\n";