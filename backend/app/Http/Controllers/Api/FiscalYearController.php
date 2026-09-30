<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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
            // ปีปัจจุบันต้องเลือกได้ตลอด แม้ยังไม่มีเอกสารใดในปีนั้น
            ->push(ThaiFiscalYear::current())
            ->map(fn ($year) => (int) $year)
            ->filter(fn ($year) => ThaiFiscalYear::isValid($year))
            ->unique()
            ->sortDesc()
            ->values();

        return response()->json(['data' => $years]);
    }
}
