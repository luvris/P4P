<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * ปีงบประมาณไทย: 1 ต.ค. (ปีก่อน) ถึง 30 ก.ย. ของปีงบนั้น
 *
 * ตัวอย่าง: ปีงบประมาณ 2569 = 1 ต.ค. 2568 ถึง 30 ก.ย. 2569
 */
class ThaiFiscalYear
{
    public const MIN = 2500;
    public const MAX = 2700;

    /** เดือนของปีงบเรียงตามรอบ (ต.ค. → ก.ย.) */
    public const MONTHS = [10, 11, 12, 1, 2, 3, 4, 5, 6, 7, 8, 9];

    public const MONTH_LABELS = [
        1 => 'มกราคม', 2 => 'กุมภาพันธ์', 3 => 'มีนาคม', 4 => 'เมษายน',
        5 => 'พฤษภาคม', 6 => 'มิถุนายน', 7 => 'กรกฎาคม', 8 => 'สิงหาคม',
        9 => 'กันยายน', 10 => 'ตุลาคม', 11 => 'พฤศจิกายน', 12 => 'ธันวาคม',
    ];

    /** ปีงบประมาณปัจจุบัน (พ.ศ.) */
    public static function current(?\DateTimeInterface $date = null): int
    {
        $date = $date ? CarbonImmutable::instance($date) : CarbonImmutable::now();
        $year = $date->year + 543;

        return $date->month >= 10 ? $year + 1 : $year;
    }

    public static function isValid(mixed $year): bool
    {
        return is_int($year) && $year >= self::MIN && $year <= self::MAX;
    }

    /** ปีปฏิทิน พ.ศ. ของเดือนนั้น ภายในปีงบที่ระบุ */
    public static function calendarYearOf(int $fiscalYear, int $month): int
    {
        return $month >= 10 ? $fiscalYear - 1 : $fiscalYear;
    }

    /** ปีงบประมาณของวันที่ (รับวันที่ในปฏิทิน ค.ศ.) */
    public static function fromDate(\DateTimeInterface $date): int
    {
        return self::current($date);
    }

    /** วันที่นี้อยู่ในปีงบประมาณที่ระบุหรือไม่ */
    public static function contains(int $fiscalYear, \DateTimeInterface $date): bool
    {
        return self::fromDate($date) === $fiscalYear;
    }

    public static function monthLabel(int $month): ?string
    {
        return self::MONTH_LABELS[$month] ?? null;
    }

    /**
     * ป้ายเดือนแบบเต็มพร้อมปี พ.ศ. เช่น "มิถุนายน 2569"
     */
    public static function periodLabel(\DateTimeInterface $date): string
    {
        $carbon = CarbonImmutable::instance($date);

        return self::monthLabel($carbon->month) . ' ' . ($carbon->year + 543);
    }
}
