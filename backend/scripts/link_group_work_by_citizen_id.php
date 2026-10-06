<?php

/**
 * เชื่อมกลุ่มงาน/งาน ของบุคลากร โดยอิงเลขบัตรประชาชน
 *
 * ไฟล์นำเข้าต้องมีหัวตาราง (หรือค่าคอลัมน์ตามลำดับ):
 *   รหัสบัตรประชาชน | กลุ่มงาน | งาน
 *
 * รองรับไฟล์ .xlsx / .tsv / .txt (คั่นด้วยแท็บ) และ .csv
 *
 * ใช้:
 *   php scripts/link_group_work_by_citizen_id.php <ไฟล์> [--apply] [--rebuild-works]
 *                                          [--skip-work="ชื่อ"] [--no-backup]
 *
 *   (ไม่มีตัวเลือก)      โหมดตรวจ — พิมพ์ผลลัพธ์ว่าจะเปลี่ยนอะไร แต่ยังไม่เขียนฐาน
 *   --apply             เขียนลงฐานข้อมูลจริง
 *   --rebuild-works     ลบงานเดิมทั้งหมดในตาราง works แล้วสร้างใหม่จากไฟล์นี้
 *                       (ใช้เมื่อไฟล์คือความจริงเดียว — ชื่องานซ้ำกันไม่ได้
 *                        งานที่ไฟล์แยกไว้หลายกลุ่มจะยึดกลุ่มที่มีคนอยู่มากที่สุด)
 *   --skip-work="ชื่อ"  ข้ามงานชื่อนี้ ใส่ซ้ำได้หลายครั้ง คั่นด้วยจุลภาค
 *   --no-backup         ข้ามการเขียนไฟล์สำรอง (ไม่แนะนำ)
 *
 * กลุ่มงานต้องมีอยู่ในตาราง groups อยู่แล้ว สคริปต์จะไม่สร้างกลุ่มงานใหม่
 * เพราะกลุ่มงานผูกกับภารกิจ ซึ่งต้องให้ผู้ดูแลตัดสินใจเอง
 *
 * ⚠️ ห้ามรันบนเซิร์ฟเวอร์จริง — สคริปต์นี้เขียนข้อมูลบุคลากรโดยตรง
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

$file = $argv[1] ?? null;
$apply = in_array('--apply', $argv, true);
$rebuildWorks = in_array('--rebuild-works', $argv, true);
$backup = ! in_array('--no-backup', $argv, true);

if (! $file || ! is_file($file)) {
    fwrite(STDERR, "ใช้: php scripts/link_group_work_by_citizen_id.php <ไฟล์ xlsx/csv/tsv> [--apply] [--rebuild-works]\n");
    exit(1);
}

/** งานที่สั่งให้ข้าม — ใช้เมื่อชื่อนั้นยังไม่ควรถูกสร้างเป็นข้อมูลอ้างอิง */
$skipWorks = [];
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--skip-work=')) {
        foreach (explode(',', substr($arg, strlen('--skip-work='))) as $name) {
            if (trim($name) !== '') {
                $skipWorks[trim($name)] = true;
            }
        }
    }
}

/**
 * เทียบชื่อโดยไม่สนใจช่องว่าง
 *
 * ไฟล์จริงเขียน "กลุ่มงานการเงินบัญชี และพัสดุ" แต่ตาราง groups เก็บเป็น
 * "กลุ่มงานการเงิน บัญชีและพัสดุ" ถ้าไม่ตัดช่องว่างทิ้งจะกลายเป็นคนละชื่อ
 */
$normalize = fn (string $value): string => preg_replace('/\s+/u', '', trim($value)) ?? trim($value);

/** เก็บเฉพาะตัวเลขของเลขบัตรประชาชน (ไฟล์จริงเขียนเป็นกลุ่มละ 5 หลักคั่นด้วยช่องว่าง) */
$digits = fn ($value): string => preg_replace('/\D/', '', (string) $value);

/** แถวที่ยังไม่มีข้อมูลเลย — ข้ามไปไม่ต้องรายงาน */
$isBlank = fn (array $row): bool => trim(implode('', array_map('strval', $row))) === '';

// ============ อ่านไฟล์ ============
if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'xlsx') {
    $rows = IOFactory::load($file)->getActiveSheet()->toArray();
    $columns = array_map('trim', $rows[0] ?? []);
    $dataRows = array_slice($rows, 1);
} else {
    $delimiter = str_ends_with(strtolower($file), '.csv') ? ',' : "\t";
    $split = fn (string $line): array => $delimiter === ','
        ? str_getcsv(rtrim($line, "\r\n"))
        : explode($delimiter, rtrim($line, "\r\n"));

    $handle = fopen($file, 'r');
    if ($handle === false) {
        fwrite(STDERR, "เปิดไฟล์ไม่ได้: {$file}\n");
        exit(1);
    }

    $first = (string) fgets($handle);
    $columns = array_map('trim', $split($first));
    $columns[0] = preg_replace('/^\xEF\xBB\xBF/', '', $columns[0] ?? '') ?? ($columns[0] ?? '');

    $dataRows = [];
    while (($line = fgets($handle)) !== false) {
        if (rtrim($line, "\r\n") !== '') {
            $dataRows[] = $split($line);
        }
    }
    fclose($handle);
}

$pick = function (array $candidates) use ($columns): ?int {
    foreach ($candidates as $candidate) {
        foreach ($columns as $i => $name) {
            if (mb_strtolower(trim((string) $name)) === mb_strtolower($candidate)) {
                return $i;
            }
        }
    }

    return null;
};

$idIndex = $pick(['รหัสบัตรประชาชน', 'รหัสประชาชน', 'เลขประจำตัวประชาชน', 'เลขบัตรประชาชน', 'บัตรประชาชน', 'id card', 'citizen_id']);
$groupIndex = $pick(['กลุ่มงาน', 'group']);
$workIndex = $pick(['งาน', 'work']);
// ใช้แค่เพื่อบอกว่าแถวไหนใช้ไม่ได้ — ถ้าไฟล์ไม่มีชื่อก็ใช้ตำแหน่งแทน
$labelIndex = $pick(['ชื่อ', 'first_name']) ?? $pick(['ตำแหน่ง', 'position']);

if ($idIndex === null) {
    fwrite(STDERR, "ไม่พบคอลัมน์เลขบัตรประชาชนในไฟล์\n");
    exit(1);
}

if ($groupIndex === null || $workIndex === null) {
    fwrite(STDERR, "ไม่พบคอลัมน์กลุ่มงาน/งาน ในไฟล์\n");
    exit(1);
}

// ============ ข้อมูลอ้างอิง ============
$groupsByName = [];
$groupsById = [];
foreach (DB::table('groups')->get() as $group) {
    $groupsByName[$normalize($group->name)] = $group;
    $groupsById[(int) $group->id] = $group->name;
}

$worksByName = [];
foreach (DB::table('works')->get() as $work) {
    $worksByName[$normalize($work->name)] = $work;
}

/**
 * นับว่างานแต่ละชื่อไปอยู่กลุ่มไหนบ้างก่อน
 *
 * ชื่องานซ้ำไม่ได้ (unique) ถ้างานเดียวกันไปอยู่หลายกลุ่ม
 * จะเลือกกลุ่มที่มีคนอยู่มากที่สุด แล้วรายงานให้ผู้ดูแลตัดสินใจต่อ
 */
$workGroups = [];
foreach ($dataRows as $row) {
    if ($isBlank($row)) {
        continue;
    }

    $groupName = trim((string) ($row[$groupIndex] ?? ''));
    $workName = trim((string) ($row[$workIndex] ?? ''));

    if ($workName === '' || isset($skipWorks[$workName]) || ! isset($groupsByName[$normalize($groupName)])) {
        continue;
    }

    $groupId = (int) $groupsByName[$normalize($groupName)]->id;
    $workGroups[$workName][$groupId] = ($workGroups[$workName][$groupId] ?? 0) + 1;
}

$workPlan = [];
foreach ($workGroups as $workName => $byGroup) {
    arsort($byGroup);
    $workPlan[$workName] = [
        'group_id' => (int) array_key_first($byGroup),
        'rows'     => array_sum($byGroup),
        'groups'   => $byGroup,
    ];
}

$workMultiGroup = array_filter($workPlan, fn ($w) => count($w['groups']) > 1);
// ARRAY_FILTER_USE_BOTH เพราะต้องใช้ชื่องาน (key) ไม่ใช่รายละเอียดของงาน (value)
$worksToCreate = array_filter(
    $workPlan,
    fn ($plan, $workName) => ! isset($worksByName[$normalize($workName)]),
    ARRAY_FILTER_USE_BOTH
);

// กลุ่มงานในไฟล์ที่ไม่มีในตาราง groups — รายงานอย่างเดียว สคริปต์ไม่สร้างให้
$groupsToCreate = [];
foreach ($dataRows as $row) {
    if ($isBlank($row)) {
        continue;
    }

    $groupName = trim((string) ($row[$groupIndex] ?? ''));
    if ($groupName !== '' && ! isset($groupsByName[$normalize($groupName)])) {
        $groupsToCreate[$groupName] = true;
    }
}

// ============ วางแผนการเขียน ============
$report = [
    'matched'     => 0,
    'both'        => 0,
    'group_only'  => 0,
    'no_data'     => 0,
    'work_blank'  => 0,
    'same_value'  => 0,
    'updated'     => 0,
    'other_group' => 0,
    'not_found'   => 0,
    'no_id'       => 0,
];

$problems = [];
$changes = [];

foreach ($dataRows as $offset => $row) {
    if ($isBlank($row)) {
        continue;
    }

    // แถวแรกหลังหัวตารางคือแถวที่ 2 ของไฟล์
    $rowNo = $offset + 2;
    $who = trim((string) ($row[$labelIndex] ?? ''));
    $label = "แถว {$rowNo}" . ($who !== '' ? " ({$who})" : '');

    $citizenId = $digits($row[$idIndex] ?? null);

    if ($citizenId === '') {
        $report['no_id']++;
        $problems[] = "{$label} — ไม่มีเลขบัตรประชาชน";
        continue;
    }

    $employee = DB::table('employees')->where('citizen_id', $citizenId)->first();

    if (! $employee) {
        $report['not_found']++;
        $problems[] = "{$label} — ไม่พบในทะเบียนบุคลากร";
        continue;
    }

    $report['matched']++;

    $groupName = trim((string) ($row[$groupIndex] ?? ''));
    $workName = trim((string) ($row[$workIndex] ?? ''));
    $group = $groupName !== '' ? ($groupsByName[$normalize($groupName)] ?? null) : null;

    if (! $group && $workName === '') {
        $report['no_data']++;
        continue;
    }

    if (! $group) {
        $problems[] = "{$label} — กลุ่มงาน \"{$groupName}\" ไม่มีในตาราง ใช้ไม่ได้";
        continue;
    }

    // งานที่จะได้ — ตอนสร้างตารางใหม่ id ยังไม่มี จึงเก็บชื่อไว้แล้วค่อยแปลงตอนเขียน
    $plannedWorkId = null;
    $plannedWorkName = null;

    if ($workName !== '' && ! isset($skipWorks[$workName])) {
        if ($rebuildWorks) {
            $plannedWorkName = isset($workPlan[$workName]) ? $workName : null;
        } else {
            $plannedWorkId = $worksByName[$normalize($workName)]->id ?? null;
            $report['work_blank'] += $plannedWorkId === null ? 1 : 0;
        }
    }

    if ($plannedWorkName !== null && (int) $workPlan[$plannedWorkName]['group_id'] !== (int) $group->id) {
        $report['other_group']++;
    } elseif ($plannedWorkId !== null && (int) $worksByName[$normalize($workName)]->group_id !== (int) $group->id) {
        $report['other_group']++;
    }

    $groupDiffers = (int) ($employee->group_id ?? 0) !== (int) $group->id;

    if ($rebuildWorks) {
        // กำลังจะสร้างตารางงานใหม่ id เดิมใช้ไม่ได้ — ถ้าคนนี้มีงานอยู่แล้วต้องอัปเดตแน่นอน
        $workDiffers = $plannedWorkName !== null || $employee->work_id !== null;
    } else {
        $workDiffers = (int) ($employee->work_id ?? 0) !== (int) ($plannedWorkId ?? 0);
    }

    if (! $groupDiffers && ! $workDiffers) {
        $report['same_value']++;
        continue;
    }

    $report[$plannedWorkName !== null || $plannedWorkId !== null ? 'both' : 'group_only']++;
    $report['updated']++;

    $changes[] = [
        'id'        => $employee->id,
        'group_id'  => (int) $group->id,
        'work_id'   => $plannedWorkId,
        'work_name' => $plannedWorkName,
    ];
}

// ============ รายงาน ============
echo $apply
    ? "โหมด: เขียนลงฐานข้อมูลจริง (--apply)\n"
    : "โหมด: ตรวจสอบอย่างเดียว — ยังไม่เขียนอะไรลงฐานข้อมูล\n";

echo $rebuildWorks
    ? "ขอบเขต: ลบงานเดิมทั้งหมดในตาราง works แล้วสร้างใหม่จากไฟล์นี้ (--rebuild-works)\n"
    : "ขอบเขต: ใช้งานที่มีอยู่ในตารางเท่านั้น ไม่สร้างงานใหม่\n";

if ($skipWorks !== []) {
    echo 'ข้ามงาน: ' . implode(', ', array_keys($skipWorks)) . "\n";
}

echo "\n———— ตารางข้อมูลอ้างอิง ————\n";
echo 'กลุ่มงานในระบบ: ' . DB::table('groups')->count() . " รายการ\n";
echo 'งานในระบบ: ' . DB::table('works')->count() . " รายการ\n";
echo 'งานที่ได้จากไฟล์นี้: ' . count($workPlan) . " รายการ\n";

if ($groupsToCreate !== []) {
    echo "\n⚠️ กลุ่มงานในไฟล์ที่ไม่มีในระบบ (สคริปต์ไม่สร้างให้ — ต้องเพิ่มในหน้าระบบก่อน):\n";
    foreach (array_keys($groupsToCreate) as $name) {
        echo "  • {$name}\n";
    }
}

if ($rebuildWorks) {
    echo "\nงานที่จะสร้างใหม่ทั้งหมด " . count($workPlan) . " รายการ:\n";
    foreach ($workPlan as $name => $plan) {
        echo "  • {$name} → " . ($groupsById[$plan['group_id']] ?? '?') . " ({$plan['rows']} แถว)\n";
    }
} elseif ($worksToCreate !== []) {
    echo "\n⚠️ งานในไฟล์ที่ยังไม่มีในระบบ (" . count($worksToCreate) . " รายการ — คนเหล่านี้จะได้แค่กลุ่มงาน):\n";
    foreach ($worksToCreate as $name => $plan) {
        echo "  • {$name} ({$plan['rows']} แถว)\n";
    }
}

if ($workMultiGroup !== []) {
    echo "\n⚠️ งานที่ในไฟล์ไปหลายกลุ่มงาน แต่ชื่องานซ้ำไม่ได้ (ยึดกลุ่มที่มีคนมากที่สุด):\n";
    foreach ($workMultiGroup as $name => $plan) {
        $parts = [];
        foreach ($plan['groups'] as $groupId => $count) {
            $parts[] = ($groupsById[$groupId] ?? '?') . " ({$count})";
        }
        echo "  • {$name}: " . implode(' | ', $parts) . "\n";
    }
}

echo "\n———— สรุป ————\n";
echo "แถวที่จับคู่ได้:            {$report['matched']}\n";
echo "  • ได้ทั้งกลุ่มงานและงาน:   {$report['both']}\n";
echo "  • ได้เฉพาะกลุ่มงาน:        {$report['group_only']}\n";
echo "  • ไม่มีข้อมูลให้แก้:       {$report['no_data']}\n";
echo "  • ตรงกับข้อมูลเดิมแล้ว:    {$report['same_value']}\n";
echo "ต้องอัปเดตทั้งหมด:           {$report['updated']}\n";
echo "ไม่พบในทะเบียน:             {$report['not_found']}\n";
echo "ไม่มีเลขบัตรประชาชน:        {$report['no_id']}\n";
echo "งานที่ยังไม่มีในระบบ:        {$report['work_blank']}\n";
echo "งานอยู่คนละกลุ่มงาน:        {$report['other_group']}\n";

if ($problems !== []) {
    $shown = array_slice($problems, 0, 25);
    echo "\n———— แถวที่ใช้ไม่ได้ (แสดง " . count($shown) . ' จาก ' . count($problems) . " แถว) ————\n";
    foreach ($shown as $problem) {
        echo "  • {$problem}\n";
    }
    if (count($problems) > count($shown)) {
        echo '  ... และอีก ' . (count($problems) - count($shown)) . " แถว\n";
    }
}

if (! $apply) {
    echo "\nยังไม่ได้เขียนอะไรลงฐานข้อมูล (โหมดตรวจ)\n";
    exit(0);
}

// ============ เขียนจริง ============
$stamp = date('Ymd-His');

if ($backup) {
    // เก็บข้อมูลเดิมไว้ก่อน เผื่อต้องย้อนกลับ (เก็บนอกโฟลเดอร์โปรเจกต์
    // เพราะไฟล์สำรองมีเลขบัตรประชาชนของบุคลากรจริง)
    $worksPath = sys_get_temp_dir() . "/works_backup_{$stamp}.csv";
    $handle = fopen($worksPath, 'w');
    fputcsv($handle, ['id', 'group_id', 'name']);
    foreach (DB::table('works')->orderBy('id')->get() as $row) {
        fputcsv($handle, [$row->id, $row->group_id, $row->name]);
    }
    fclose($handle);

    $peoplePath = sys_get_temp_dir() . "/employees_group_work_backup_{$stamp}.csv";
    $handle = fopen($peoplePath, 'w');
    fputcsv($handle, ['id', 'citizen_id', 'group_id', 'work_id']);
    foreach (DB::table('employees')->orderBy('id')->get(['id', 'citizen_id', 'group_id', 'work_id']) as $row) {
        fputcsv($handle, [$row->id, $row->citizen_id, $row->group_id, $row->work_id]);
    }
    fclose($handle);

    echo "\nสำรองข้อมูลเดิมไว้ที่:\n  {$worksPath}\n  {$peoplePath}\n";
}

DB::transaction(function () use ($rebuildWorks, $workPlan, $changes) {
    if ($rebuildWorks) {
        // ยังไม่เคยมีใครอ้างอิงงานจากฝั่งพนักงาน (employees.work_id เป็น null ทั้งหมด)
        // และ foreign key ตั้งเป็น SET NULL — ลบได้โดยไม่ทำให้แถวบุคลากรหาย
        DB::table('works')->delete();

        foreach ($workPlan as $workName => $plan) {
            DB::table('works')->insert([
                'group_id'   => $plan['group_id'],
                'name'       => $workName,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    // หลังสร้างตารางใหม่ id ของงานถึงจะถูกต้อง จึงแปลงชื่อเป็น id ตรงนี้
    $worksByName = DB::table('works')->pluck('id', 'name');

    foreach ($changes as $change) {
        $workId = $change['work_id'];

        if ($change['work_name'] !== null) {
            $workId = $worksByName[$change['work_name']] ?? null;
        }

        DB::table('employees')->where('id', $change['id'])->update([
            'group_id'   => $change['group_id'],
            'work_id'    => $workId,
            'updated_at' => now(),
        ]);
    }
});

echo "\nเขียนลงฐานข้อมูลเรียบร้อย — อัปเดต {$report['updated']} คน\n";