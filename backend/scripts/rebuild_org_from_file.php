<?php

/**
 * ปรับตาราง duties / groups / works ตามไฟล์ Excel แล้ว link พนักงานด้วยเลขบัตรประชาชน
 *
 * ไฟล์ต้องมีหัวตาราง (หาตำแหน่งคอลัมน์จากชื่ออัตโนมัติ):
 *   รหัสบัตรประชาชน | ... | ภารกิจ | กลุ่มงาน | งาน
 *
 * ใช้:
 *   php scripts/rebuild_org_from_file.php <ไฟล์ xlsx> [--apply]
 *   (ไม่มี --apply) โหมดตรวจ — รายงานแผนทั้งหมด ยังไม่เขียนฐาน
 *   --apply         เขียนลงฐานจริง (สำรองอัตโนมัติก่อนเขียน)
 *
 * หลักการ:
 *  - duties/groups/works ยึดไฟล์เป็นความจริงเดียว:
 *      ชื่อไม่มีในไฟล์ → ลบ | ชื่อใหม่ในไฟล์ → เพิ่ม
 *      ชื่อตรงแต่กลุ่มแม่ไม่ตรง → แก้ duty_id/group_id (เทียบเท่าลบ+สร้างใหม่
 *      แต่รักษา id ไม่ให้ link ของพนักงานที่ชื่อยังตรงตายตาม)
 *  - ชื่องานซ้ำหลายกลุ่ม → ยึดกลุ่มที่มีแถวในไฟล์มากที่สุด (เสมอ → แถวแรก) พร้อมรายงาน
 *  - link พนักงานด้วยเลขบัตรประชาชน (ตัดช่องว่าง/ขีด) ตามเส้นทาง ภารกิจ→กลุ่มงาน→งาน
 *    ช่องว่างในไฟล์ = ไม่ระบุสังกัดระดับนั้น
 *  - พนักงานที่ไม่มีในไฟล์ไม่ถูกแก้เอง — แต่ถ้ารายการที่ผูกอยู่ถูกลบ
 *    FK ON DELETE SET NULL จะทำให้ค่าว่างลงอัตโนมัติ (รายงานไว้)
 *
 * ⚠️ ห้ามรันบนเซิร์ฟเวอร์จริงโดยไม่สำรองฐานก่อน (แม้สคริปต์จะสำรองให้)
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

$file = $argv[1] ?? null;
$apply = in_array('--apply', $argv, true);

if (! $file || ! is_file($file)) {
    fwrite(STDERR, "ใช้: php scripts/rebuild_org_from_file.php <ไฟล์ xlsx> [--apply]\n");
    exit(1);
}

/** เทียบชื่อโดยไม่สนใจช่องว่าง */
$norm = fn ($v): string => preg_replace('/\s+/u', '', trim((string) $v)) ?? trim((string) $v);
/** ชื่อสำหรับบันทึกลงฐาน — ตัดช่องว่างซ้ำ เหลือช่องว่างเดียว */
$display = fn ($v): string => trim(preg_replace('/\s+/u', ' ', (string) $v) ?? '');
/** เก็บเฉพาะตัวเลขของเลขบัตรประชาชน (ไฟล์จริงคั่นด้วยช่องว่าง) */
$digits = fn ($v): string => preg_replace('/\D/', '', (string) $v);

// ============ อ่านไฟล์ ============
$rows = IOFactory::load($file)->getActiveSheet()->toArray();
$col = ['cid' => null, 'duty' => null, 'group' => null, 'work' => null];
foreach ($rows[0] ?? [] as $i => $h) {
    $n = $norm($h);
    if ($col['cid'] === null && str_contains($n, 'ประชาชน')) { $col['cid'] = $i; continue; }
    if ($col['group'] === null && str_contains($n, 'กลุ่มงาน')) { $col['group'] = $i; continue; }
    if ($col['duty'] === null && str_contains($n, 'ภารกิจ')) { $col['duty'] = $i; continue; }
    if ($col['work'] === null && $n === 'งาน') { $col['work'] = $i; }
}
if ($col['work'] === null) {
    foreach ($rows[0] ?? [] as $i => $h) {
        $n = $norm($h);
        if (str_contains($n, 'งาน') && ! str_contains($n, 'กลุ่ม') && ! str_contains($n, 'ภารกิจ')) { $col['work'] = $i; break; }
    }
}
foreach ($col as $k => $v) {
    if ($v === null) {
        fwrite(STDERR, "ไม่พบคอลัมน์ {$k} — หัวตาราง: " . json_encode($rows[0] ?? [], JSON_UNESCAPED_UNICODE) . "\n");
        exit(1);
    }
}

$dutiesF = [];   // norm => display
$groupsF = [];   // norm => ['display', 'duty' => norm|null]
$worksF = [];    // norm => ['display', 'groups' => [groupNorm => จำนวนแถว]]
$citizensF = []; // digits => ['duty','group','work'] (norm)
$dupCitizens = [];
$orgNoCid = 0;
$dataRows = 0;

foreach (array_slice($rows, 1) as $r) {
    if (trim(implode('', array_map('strval', $r))) === '') {
        continue;
    }
    $dataRows++;
    $dn = $norm($r[$col['duty']] ?? null);
    $gn = $norm($r[$col['group']] ?? null);
    $wn = $norm($r[$col['work']] ?? null);

    if ($dn !== '') {
        $dutiesF[$dn] ??= $display($r[$col['duty']]);
    }
    if ($gn !== '') {
        $groupsF[$gn] ??= ['display' => $display($r[$col['group']]), 'duty' => $dn !== '' ? $dn : null];
        if ($groupsF[$gn]['duty'] === null && $dn !== '') {
            $groupsF[$gn]['duty'] = $dn;
        }
    }
    if ($wn !== '') {
        $worksF[$wn] ??= ['display' => $display($r[$col['work']]), 'groups' => []];
        $key = $gn !== '' ? $gn : '(none)';
        $worksF[$wn]['groups'][$key] = ($worksF[$wn]['groups'][$key] ?? 0) + 1;
    }

    $cid = $digits($r[$col['cid']] ?? null);
    if ($cid !== '') {
        if (isset($citizensF[$cid])) {
            $dupCitizens[] = $cid;
        }
        $citizensF[$cid] = ['duty' => $dn, 'group' => $gn, 'work' => $wn];
    } elseif ($dn !== '' || $gn !== '' || $wn !== '') {
        $orgNoCid++;
    }
}

// งานชื่อซ้ำหลายกลุ่ม → ยึดกลุ่มที่มีแถวมากที่สุด (PHP 8 sort มีเสถียรภาพ → เสมอ = แถวแรกที่เจอ)
$workParent = [];
$workConflicts = [];
foreach ($worksF as $wn => $info) {
    arsort($info['groups']);
    $best = array_key_first($info['groups']);
    $workParent[$wn] = $best === '(none)' ? null : $best;
    if (count($info['groups']) > 1) {
        $workConflicts[$wn] = $info['groups'];
    }
}

// ============ ตารางปัจจุบัน ============
$dbD = DB::table('duties')->get(['id', 'name'])->keyBy(fn ($x) => $norm($x->name));
$dbG = DB::table('groups')->get(['id', 'name', 'duty_id'])->keyBy(fn ($x) => $norm($x->name));
$dbW = DB::table('works')->get(['id', 'name', 'group_id'])->keyBy(fn ($x) => $norm($x->name));

$delD = $dbD->keys()->diff(array_keys($dutiesF))->sort()->values();
$addD = collect(array_keys($dutiesF))->diff($dbD->keys())->sort()->values();
$updD = [];
foreach ($dutiesF as $n => $d) {
    if ($dbD->has($n) && $dbD[$n]->name !== $d) $updD[$n] = $d;
}

$delG = $dbG->keys()->diff(array_keys($groupsF))->sort()->values();
$addG = collect(array_keys($groupsF))->diff($dbG->keys())->sort()->values();
$fixG = [];
$updG = [];
foreach ($groupsF as $n => $info) {
    if (! $dbG->has($n)) continue;
    $dbParentName = $dbG[$n]->duty_id !== null ? $dbD->firstWhere('id', $dbG[$n]->duty_id)?->name : null;
    $dbParentNorm = $dbParentName !== null ? $norm($dbParentName) : null;
    if ($dbParentNorm !== $info['duty']) {
        $fixG[$n] = ['db' => $dbParentNorm, 'file' => $info['duty']];
    } elseif ($dbG[$n]->name !== $info['display']) {
        $updG[$n] = $info['display'];
    }
}

$delW = $dbW->keys()->diff(array_keys($worksF))->sort()->values();
$addW = collect(array_keys($worksF))->diff($dbW->keys())->sort()->values();
$fixW = [];
$updW = [];
foreach ($worksF as $n => $info) {
    if (! $dbW->has($n)) continue;
    $dbParentName = $dbW[$n]->group_id !== null ? $dbG->firstWhere('id', $dbW[$n]->group_id)?->name : null;
    $dbParentNorm = $dbParentName !== null ? $norm($dbParentName) : null;
    if ($dbParentNorm !== $workParent[$n]) {
        $fixW[$n] = ['db' => $dbParentNorm, 'file' => $workParent[$n]];
    } elseif ($dbW[$n]->name !== $info['display']) {
        $updW[$n] = $info['display'];
    }
}

// ============ แผนการ link พนักงาน ============
$empByCid = DB::table('employees')->get(['id', 'citizen_id', 'duty_id', 'group_id', 'work_id'])
    ->groupBy(fn ($e) => $digits($e->citizen_id));
$empTotal = DB::table('employees')->count();

$matchedRows = 0;
$multiCid = [];
$notFoundCid = [];
foreach ($citizensF as $cid => $_path) {
    $g = $empByCid->get($cid);
    if ($g === null || $g->isEmpty()) {
        $notFoundCid[] = $cid;
        continue;
    }
    $matchedRows += $g->count();
    if ($g->count() > 1) $multiCid[$cid] = $g->count();
}

$claimEmps = DB::select(
    'SELECT DISTINCT e.id, e.citizen_id, e.first_name, e.last_name
     FROM travel_expense_claim_items i JOIN employees e ON e.id = i.employee_id'
);

// ============ รายงาน ============
$line = fn (string $t) => str_repeat('-', 8) . $t . str_repeat('-', 8);

echo $line(' ไฟล์ ') . PHP_EOL;
echo "แถวข้อมูล={$dataRows} เลขบัตรไม่ซ้ำ=" . count($citizensF) . " (ซ้ำในไฟล์=" . count($dupCitizens) . ")"
    . " ภารกิจ=" . count($dutiesF) . " กลุ่มงาน=" . count($groupsF) . " งาน=" . count($worksF)
    . " แถวมีสังกัดแต่ไม่มีเลขบัตร={$orgNoCid}" . PHP_EOL;

echo $line(' duties ') . PHP_EOL;
echo 'ลบ (' . $delD->count() . '): ' . $delD->map(fn ($n) => $dbD[$n]->name)->implode(' | ') . PHP_EOL;
echo 'เพิ่ม (' . $addD->count() . '): ' . $addD->map(fn ($n) => $dutiesF[$n])->implode(' | ') . PHP_EOL;
echo 'แก้ชื่อ (' . count($updD) . ')' . PHP_EOL;

echo $line(' groups ') . PHP_EOL;
echo 'ลบ (' . $delG->count() . '): ' . $delG->map(fn ($n) => $dbG[$n]->name)->implode(' | ') . PHP_EOL;
echo 'เพิ่ม (' . $addG->count() . '): ' . $addG->map(fn ($n) => $groupsF[$n]['display'])->implode(' | ') . PHP_EOL;
echo 'แก้กลุ่มแม่ (' . count($fixG) . '): ' . collect($fixG)
    ->map(fn ($f, $n) => $groupsF[$n]['display'] . ': ' . ($f['db'] ?? '(ไม่มี)') . ' → ' . ($f['file'] ?? '(ไม่มี)'))
    ->implode(' | ') . PHP_EOL;
echo 'แก้ชื่อ (' . count($updG) . ')' . PHP_EOL;

echo $line(' works ') . PHP_EOL;
echo 'ลบ (' . $delW->count() . '): ' . $delW->map(fn ($n) => $dbW[$n]->name)->implode(' | ') . PHP_EOL;
echo 'เพิ่ม (' . $addW->count() . '): ' . $addW->map(fn ($n) => $worksF[$n]['display'])->implode(' | ') . PHP_EOL;
echo 'แก้กลุ่มแม่ (' . count($fixW) . ')' . PHP_EOL;
echo 'แก้ชื่อ (' . count($updW) . ')' . PHP_EOL;
foreach ($workConflicts as $wn => $gs) {
    echo "  ! งานชื่อซ้ำหลายกลุ่ม: {$worksF[$wn]['display']} → " . json_encode($gs, JSON_UNESCAPED_UNICODE)
        . ' ยึด ' . ($workParent[$wn] ?? '(ไม่มีกลุ่ม)') . PHP_EOL;
}

echo $line(' แผน link พนักงาน ') . PHP_EOL;
echo "พนักงานทั้งหมด={$empTotal} แถวที่จะถูก link={$matchedRows} (เลขบัตรซ้ำในตาราง=" . count($multiCid) . ')' . PHP_EOL;
echo 'เลขบัตรในไฟล์ที่ไม่พบในทะเบียน (' . count($notFoundCid) . '): '
    . implode(', ', array_slice($notFoundCid, 0, 20)) . (count($notFoundCid) > 20 ? ' …' : '') . PHP_EOL;
echo 'พนักงานที่ไม่มีในไฟล์=' . ($empTotal - $matchedRows) . ' (ไม่ถูกแก้เอง — แต่จะว่างเองถ้ารายการที่ผูกถูกลบ)' . PHP_EOL;

echo $line(' ผู้เบิกในใบเบิก (ตรวจสอบผลกระทบ) ') . PHP_EOL;
foreach ($claimEmps as $e) {
    $cid = $digits($e->citizen_id);
    $path = $citizensF[$cid] ?? null;
    echo "emp#{$e->id} {$e->first_name} {$e->last_name} → "
        . ($path !== null
            ? ($path['duty'] ?: '-') . ' / ' . ($path['group'] ?: '-') . ' / ' . ($path['work'] ?: '-')
            : 'ไม่มีในไฟล์ (สังกัดจะว่าง)')
        . PHP_EOL;
}

if (! $apply) {
    echo PHP_EOL . '[โหมดตรวจ] ยังไม่เขียนฐาน — รันซ้ำพร้อม --apply เพื่อดำเนินการ' . PHP_EOL;
    exit(0);
}

// ============ สำรองฐาน ============
$backupPath = storage_path('app/org-backup-' . date('Ymd-His') . '.json');
$backup = [
    'taken_at' => now()->toIso8601String(),
    'duties' => DB::table('duties')->get(),
    'groups' => DB::table('groups')->get(),
    'works' => DB::table('works')->get(),
    'employees_org' => DB::table('employees')->get(['id', 'citizen_id', 'duty_id', 'group_id', 'work_id']),
];
file_put_contents($backupPath, json_encode($backup, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
echo PHP_EOL . 'สำรองฐานแล้ว: ' . $backupPath . PHP_EOL;

// ============ เขียนฐาน ============
// ลำดับสำคัญ: ยังไม่ลบอะไรจนกว่าจะย้ายกลุ่มแม่ให้พ้น — FK groups→duties / works→groups เป็น CASCADE
DB::transaction(function () use (
    $dbD, $dbG, $dbW, $delD, $delG, $delW, $addD, $addG, $addW, $updD, $updG, $updW, $fixG, $fixW,
    $dutiesF, $groupsF, $worksF, $workParent, $citizensF, $empByCid
) {
    $now = now();

    // 1) duties — เพิ่ม/แก้ชื่อ (ยังไม่ลบของเก่า)
    $dutyId = [];
    foreach ($dbD as $n => $row) $dutyId[$n] = $row->id;
    foreach ($updD as $n => $name) DB::table('duties')->where('id', $dutyId[$n])->update(['name' => $name, 'updated_at' => $now]);
    foreach ($addD as $n) $dutyId[$n] = DB::table('duties')->insertGetId(['name' => $dutiesF[$n], 'created_at' => $now, 'updated_at' => $now]);

    // 2) groups — เพิ่ม/แก้ชื่อ/ย้ายกลุ่มแม่ (ยังไม่ลบของเก่า)
    $groupId = [];
    foreach ($dbG as $n => $row) $groupId[$n] = $row->id;
    foreach ($updG as $n => $name) DB::table('groups')->where('id', $groupId[$n])->update(['name' => $name, 'updated_at' => $now]);
    foreach ($fixG as $n => $fx) {
        DB::table('groups')->where('id', $groupId[$n])->update([
            'duty_id' => $fx['file'] !== null ? ($dutyId[$fx['file']] ?? null) : null,
            'updated_at' => $now,
        ]);
    }
    foreach ($addG as $n) {
        $groupId[$n] = DB::table('groups')->insertGetId([
            'duty_id' => $groupsF[$n]['duty'] !== null ? ($dutyId[$groupsF[$n]['duty']] ?? null) : null,
            'name' => $groupsF[$n]['display'],
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    // 3) works — เพิ่ม/แก้ชื่อ/ย้ายกลุ่มแม่ + ลบของที่ไม่ตรงไฟล์
    $workId = [];
    foreach ($dbW as $n => $row) $workId[$n] = $row->id;
    foreach ($updW as $n => $name) DB::table('works')->where('id', $workId[$n])->update(['name' => $name, 'updated_at' => $now]);
    foreach ($fixW as $n => $fx) {
        DB::table('works')->where('id', $workId[$n])->update([
            'group_id' => $fx['file'] !== null ? ($groupId[$fx['file']] ?? null) : null,
            'updated_at' => $now,
        ]);
    }
    foreach ($addW as $n) {
        $workId[$n] = DB::table('works')->insertGetId([
            'group_id' => $workParent[$n] !== null ? ($groupId[$workParent[$n]] ?? null) : null,
            'name' => $worksF[$n]['display'],
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
    if ($delW->isNotEmpty()) DB::table('works')->whereIn('id', $delW->map(fn ($n) => $dbW[$n]->id))->delete();

    // 4) ค่อยลบ groups ที่ไม่ตรงไฟล์ (works ย้ายกลุ่มแม่พ้นแล้ว) แล้วจึงลบ duties
    if ($delG->isNotEmpty()) DB::table('groups')->whereIn('id', $delG->map(fn ($n) => $dbG[$n]->id))->delete();
    if ($delD->isNotEmpty()) DB::table('duties')->whereIn('id', $delD->map(fn ($n) => $dbD[$n]->id))->delete();

    // 5) link พนักงานด้วยเลขบัตรประชาชน
    $linked = 0;
    $changed = 0;
    foreach ($citizensF as $cid => $path) {
        $emps = $empByCid->get($cid);
        if ($emps === null || $emps->isEmpty()) continue;
        $dutyIdV = $path['duty'] !== '' ? ($dutyId[$path['duty']] ?? null) : null;
        $groupIdV = $path['group'] !== '' ? ($groupId[$path['group']] ?? null) : null;
        $workIdV = null;
        if ($path['work'] !== '' && $workParent[$path['work']] !== null) {
            $workIdV = $workId[$path['work']] ?? null;
        }
        foreach ($emps as $e) {
            $chg = [];
            if ($e->duty_id !== $dutyIdV) $chg['duty_id'] = $dutyIdV;
            if ($e->group_id !== $groupIdV) $chg['group_id'] = $groupIdV;
            if ($e->work_id !== $workIdV) $chg['work_id'] = $workIdV;
            if ($chg) {
                $chg['updated_at'] = $now;
                DB::table('employees')->where('id', $e->id)->update($chg);
                $changed++;
            }
            $linked++;
        }
    }
    echo "link สำเร็จ={$linked} แถว (เปลี่ยนจริง={$changed} แถว)" . PHP_EOL;
});

// ============ รายงานหลังเขียน ============
echo $line(' หลังใช้งาน ') . PHP_EOL;
echo 'duties=' . DB::table('duties')->count()
    . ' groups=' . DB::table('groups')->count()
    . ' works=' . DB::table('works')->count() . PHP_EOL;
$full = DB::table('employees')->whereNotNull('duty_id')->whereNotNull('group_id')->whereNotNull('work_id')->count();
$some = DB::table('employees')->where(fn ($q) => $q->whereNotNull('duty_id')->orWhereNotNull('group_id')->orWhereNotNull('work_id'))->count() - $full;
$none = $empTotal - $full - $some;
echo "พนักงาน: สังกัดครบ={$full} บางส่วน={$some} ไม่มีเลย={$none} (จากทั้งหมด={$empTotal})" . PHP_EOL;
