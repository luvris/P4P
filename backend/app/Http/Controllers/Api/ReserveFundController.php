<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Import;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReserveFundController extends Controller
{
    // GET /api/hr/reserve-fund/imports
    // รายชื่อ import ที่มีข้อมูล payroll (ใช้เลือกช่วงข้อมูล)
    public function imports(): JsonResponse
    {
        $imports = Import::whereHas('payrolls')
            ->orderBy('created_at', 'desc')
            ->get(['id', 'file_name', 'created_at']);

        return response()->json([
            'data' => $imports,
        ]);
    }

    // GET /api/hr/reserve-fund?import_id=
    // คำนวณเงินสำรอง 3% จาก payroll.net_income
    // จัดกลุ่มตาม ภารกิจ (duty) → กลุ่มงาน (group) → งาน (work)
    public function summary(Request $request): JsonResponse
    {
        // ใช้ import ล่าสุดถ้าไม่ระบุ
        $importId = $request->input('import_id')
            ?: DB::table('payrolls')->max('import_id');

        $import = $importId ? Import::find($importId) : null;

        $rows = DB::table('payrolls as p')
            ->leftJoin('employees as e', 'e.citizen_id', '=', 'p.citizen_id')
            ->leftJoin('duties as d', 'd.id', '=', 'e.duty_id')
            ->leftJoin('groups as g', 'g.id', '=', 'e.group_id')
            ->leftJoin('works as w', 'w.id', '=', 'e.work_id')
            ->leftJoin('employee_statuses as es', 'es.id', '=', 'e.status_id')
            ->when($importId, fn ($q) => $q->where('p.import_id', $importId))
            ->where(function ($q) {
                // ไม่นำบุคลากรสถานะ "ลาออก" มาคำนวณ
                $q->whereNull('es.id')
                    ->orWhere('es.name', '!=', 'ลาออก');
            })
            ->select(
                'd.id as duty_id',
                'd.name as duty_name',
                'g.id as group_id',
                'g.name as group_name',
                'w.id as work_id',
                'w.name as work_name',
                DB::raw('SUM(p.net_income) as net_income'),
                DB::raw('COUNT(*) as employee_count')
            )
            ->groupBy('d.id', 'd.name', 'g.id', 'g.name', 'w.id', 'w.name')
            ->get();

        $duties = [];
        $totalNetIncome = 0.0;
        $totalEmployees = 0;

        foreach ($rows as $row) {
            $netIncome = (float) $row->net_income;
            $count = (int) $row->employee_count;

            $totalNetIncome += $netIncome;
            $totalEmployees += $count;

            $dutyKey = $row->duty_id ?? 'none';

            if (!isset($duties[$dutyKey])) {
                $duties[$dutyKey] = [
                    'id' => $row->duty_id,
                    'name' => $row->duty_name ?? 'ไม่ระบุภารกิจ',
                    'net_income' => 0.0,
                    'reserve_3_percent' => 0.0,
                    'employee_count' => 0,
                    'works' => [],
                ];
            }

            $duties[$dutyKey]['net_income'] += $netIncome;
            $duties[$dutyKey]['employee_count'] += $count;

            $workKey = ($row->work_id ?? 'none') . '|' . ($row->group_id ?? 'none');

            if (!isset($duties[$dutyKey]['works'][$workKey])) {
                $duties[$dutyKey]['works'][$workKey] = [
                    'id' => $row->work_id,
                    'group_id' => $row->group_id,
                    'group_name' => $row->group_name ?? 'ไม่ระบุกลุ่มงาน',
                    'name' => $row->work_name ?? 'ไม่ระบุงาน',
                    'net_income' => 0.0,
                    'reserve_3_percent' => 0.0,
                    'employee_count' => 0,
                ];
            }

            $duties[$dutyKey]['works'][$workKey]['net_income'] += $netIncome;
            $duties[$dutyKey]['works'][$workKey]['employee_count'] += $count;
        }

        // คำนวณสำรอง 3% + จัดเรียง
        $dutyList = [];
        foreach ($duties as $duty) {
            $duty['reserve_3_percent'] = round($duty['net_income'] * 0.03, 2);
            $duty['net_income'] = round($duty['net_income'], 2);

            $duty['works'] = array_values(array_map(function ($work) {
                $work['reserve_3_percent'] = round($work['net_income'] * 0.03, 2);
                $work['net_income'] = round($work['net_income'], 2);
                return $work;
            }, $duty['works']));

            // จัดเรียงงานตามชื่อ
            usort($duty['works'], fn ($a, $b) => strcmp($a['name'], $b['name']));

            $dutyList[] = $duty;
        }

        usort($dutyList, fn ($a, $b) => strcmp($a['name'], $b['name']));

        return response()->json([
            'data' => [
                'duties' => $dutyList,
            ],
            'summary' => [
                'total_net_income' => round($totalNetIncome, 2),
                'total_reserve' => round($totalNetIncome * 0.03, 2),
                'total_employees' => $totalEmployees,
                'import_id' => $importId,
                'import_name' => $import?->file_name,
                'imported_at' => $import?->created_at,
            ],
        ]);
    }
}