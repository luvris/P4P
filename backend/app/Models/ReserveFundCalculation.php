<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * ผลการคำนวณเงินสำรองที่บันทึกไว้ แยกตามปีงบประมาณ
 */
class ReserveFundCalculation extends Model
{
    use HasFactory;

    protected $fillable = [
        'fiscal_year',
        'import_id',
        'period_month',
        'period_year',
        'percent',
        'total_income_base',
        'total_reserve',
        'total_employees',
        'status',
        'confirmed_at',
        'confirmed_by',
        'income_breakdown',
        'duty_breakdown',
        'note',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'fiscal_year'       => 'integer',
        'period_month'      => 'integer',
        'period_year'       => 'integer',
        'percent'           => 'decimal:2',
        'total_income_base' => 'decimal:2',
        'total_reserve'     => 'decimal:2',
        'total_employees'   => 'integer',
        'confirmed_at'      => 'datetime',
        'income_breakdown'  => 'array',
        'duty_breakdown'    => 'array',
    ];

    public const STATUS_DRAFT = 'draft';
    public const STATUS_CONFIRMED = 'confirmed';

    // ========== Relationships ==========

    public function import()
    {
        return $this->belongsTo(Import::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function confirmer()
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    // ========== Scopes ==========

    /** เฉพาะงวดที่ยืนยันแล้ว — ใช้รวมเป็นยอดสะสม */
    public function scopeConfirmed($query)
    {
        return $query->where('status', self::STATUS_CONFIRMED);
    }

    public function scopeForFiscalYear($query, int $fiscalYear)
    {
        return $query->where('fiscal_year', $fiscalYear);
    }

    // ========== Helpers ==========

    /**
     * ปีงบประมาณไทยปัจจุบัน (เริ่ม 1 ต.ค.)
     * ต.ค.–ธ.ค. → พ.ศ. ปีถัดไป
     */
    public static function currentFiscalYear(?\DateTimeInterface $date = null): int
    {
        $date = $date ?? now();
        $year = (int) $date->format('Y') + 543;

        return (int) $date->format('n') >= 10 ? $year + 1 : $year;
    }

    /**
     * ลำดับเดือนในปีงบประมาณ (ต.ค. = 1 ... ก.ย. = 12)
     * ใช้จัดเรียงงวดให้ตรงกับรอบปีงบ ไม่ใช่ปีปฏิทิน
     */
    public static function fiscalMonthOrder(int $month): int
    {
        return $month >= 10 ? $month - 9 : $month + 3;
    }

    /**
     * ปีปฏิทิน (พ.ศ.) ของงวดเดือนนั้น ภายในปีงบที่ระบุ
     * ต.ค.–ธ.ค. อยู่ในปีปฏิทินก่อนปีงบ
     */
    public static function calendarYearOf(int $fiscalYear, int $month): int
    {
        return $month >= 10 ? $fiscalYear - 1 : $fiscalYear;
    }

    /**
     * 12 งวดของปีงบประมาณ เรียงตามรอบปีงบ (ต.ค. → ก.ย.)
     */
    public static function fiscalMonths(): array
    {
        return [10, 11, 12, 1, 2, 3, 4, 5, 6, 7, 8, 9];
    }

    /** ชื่อเดือนภาษาไทยแบบย่อ */
    public static function monthLabel(?int $month): ?string
    {
        $labels = [
            1 => 'ม.ค.', 2 => 'ก.พ.', 3 => 'มี.ค.', 4 => 'เม.ย.',
            5 => 'พ.ค.', 6 => 'มิ.ย.', 7 => 'ก.ค.', 8 => 'ส.ค.',
            9 => 'ก.ย.', 10 => 'ต.ค.', 11 => 'พ.ย.', 12 => 'ธ.ค.',
        ];

        return $month !== null ? ($labels[$month] ?? null) : null;
    }

    /** งวดนี้ยืนยันแล้วหรือยัง */
    public function isConfirmed(): bool
    {
        return $this->status === self::STATUS_CONFIRMED;
    }
}
