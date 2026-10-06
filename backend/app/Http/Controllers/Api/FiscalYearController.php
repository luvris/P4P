<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payroll;
use App\Models\ReserveFundCalculation;
use App\Models\TravelExpenseClaim;
use App\Support\ThaiFiscalYear;
use Illuminate\Http\JsonResponse;

/**
 * ปีงบประมาณสำหรับตัวเลือกบน Header — ใช้ร่วมกันทุกโมดูล
 *
 * อ่านได้ทุก role ที่ login แล้ว เพราะเป็น state ระดับแอป ไม่ใช่ข้อมูลของโมดูลใดโมดูลหนึ่ง
 * (คืนเฉพาะตัวเลขปี ไม่มียอดเงินหรือรายละเอียดเอกสารใด ๆ)
 */
class FiscalYearController extends Controller
{
    /**
     * GET /api/fiscal-years
     * รวมปีงบจากทุกแหล่งที่มีข้อมูลจริง + ปีงบปัจจุบันเสมอ
     */
    public function index(): JsonResponse
    {
        $years = collect()
            ->merge(ReserveFundCalculation::query()->distinct()->pluck('fiscal_year'))
            ->merge(TravelExpenseClaim::query()->distinct()->pluck('fiscal_year'))
            // ปีงบที่มีข้อมูลเงินเดือนต้องเลือกได้เสมอ
            // ไม่งั้นพอผลการคำนวณเงินสำรองถูกล้าง ผู้ใช้จะกลับไปดูปีที่มี payroll อยู่ไม่ได้เลย
            ->merge($this->payrollFiscalYears())
            // ปีปัจจุบันต้องเลือกได้ตลอด แม้ยังไม่มีเอกสารใดในปีนั้น
            ->push(ThaiFiscalYear::current())
            ->map(fn ($year) => (int) $year)
            ->filter(fn ($year) => ThaiFiscalYear::isValid($year))
            ->unique()
            ->sortDesc()
            ->values();

        return response()->json(['data' => $years]);
    }

    /**
     * ปีงบประมาณที่อนุมานได้จากแถวเงินเดือน
     *
     * ใช้งวดจริงในแถว (ปี + เดือน) เป็นหลัก เพราะปีงบที่เก็บไว้ในคอลัมน์อาจเก่ากว่า
     * ข้อมูลที่แก้ไขในไฟล์ต้นทาง — งวด ต.ค.–ธ.ค. นับเป็นปีงบของปีถัดไป
     *
     * @return array<int, int>
     */
    protected function payrollFiscalYears(): array
    {
        // ใช้ query builder ตรง ๆ ไม่ hydrate เป็น model เพราะคืนค่าเป็นตัวเลข
        // ไม่ใช่ model — ถ้าเป็น Eloquent Collection จะ merge กันไม่ได้
        $fromPeriod = Payroll::query()
            ->toBase()
            ->select('period_year', 'period_month')
            ->whereNotNull('period_year')
            ->whereNotNull('period_month')
            ->distinct()
            ->get()
            ->map(fn ($row) => (int) $row->period_month >= 10
                ? (int) $row->period_year + 1
                : (int) $row->period_year);

        $fromColumn = Payroll::query()
            ->toBase()
            ->whereNotNull('fiscal_year')
            ->distinct()
            ->pluck('fiscal_year')
            ->map(fn ($year) => (int) $year);

        return $fromPeriod->merge($fromColumn)->all();
    }
}
