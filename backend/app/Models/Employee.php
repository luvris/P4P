<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Support\ThaiFiscalYear;

class Employee extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'citizen_id',
        'employee_id', // PID - รหัสพนักงานภายใน
        'prefix_id',
        'first_name',
        'last_name',
        'position_number',
        'salary',
        'employee_type_id',
        'position_id',
        'duty_id',
        'group_id',
        'work_id',
        'status_id',
        'bank_account',
        'bank_account_2',
        // ข้อมูลจากไฟล์เงินเดือนรูปแบบใหม่ (งวดล่าสุดของแต่ละคน)
        'latest_salary',
        'latest_period_year',
        'latest_period_month',
        'note',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'salary' => 'decimal:2',
        'latest_salary' => 'decimal:2',
        'latest_period_month' => 'integer',
    ];

    protected $appends = [
        'full_name',
        'bank_account_number',
        // เงินเดือนจากไฟล์งวดล่าสุด — หน้ารายชื่อ/แผงแก้ไข ใช้ค่าที่แนบมากับแถวโดยตรง
        'latest_payroll_income',
        'income_period_label',
    ];

    // ========== Relationships ==========

    public function prefix()
    {
        return $this->belongsTo(Prefix::class);
    }

    public function employeeType()
    {
        return $this->belongsTo(EmployeeType::class);
    }

    public function position()
    {
        return $this->belongsTo(Position::class);
    }

    public function duty()
    {
        return $this->belongsTo(Duty::class);
    }

    public function group()
    {
        return $this->belongsTo(Group::class);
    }

    public function work()
    {
        return $this->belongsTo(Work::class);
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function status()
    {
        return $this->belongsTo(EmployeeStatus::class);
    }

    /**
     * เชื่อมกับ payrolls ผ่าน citizen_id (ไม่ใช่ id)
     */
    public function payrolls()
    {
        return $this->hasMany(Payroll::class, 'citizen_id', 'citizen_id');
    }

    /**
     * ประวัติการปรับฐานเงินเดือน
     */
    public function salaryAdjustments()
    {
        return $this->hasMany(SalaryAdjustment::class);
    }

    /**
     * ประวัติการจ้างงาน
     */
    public function employmentHistories()
    {
        return $this->hasMany(EmploymentHistory::class, 'employee_id');
    }

    /**
     * ข้อมูล payroll ล่าสุด"ตามงวด" (ไม่ใช่ตาม id ที่นำเข้าล่าสุด)
     *
     * ไฟล์เงินเดือนรูปแบบใหม่มีหลายงวดในไฟล์เดียว และ "ปีงบประมาณ" เริ่มที่เดือน 10
     * (ต.ค. → ก.ย.) จึงเรียงตามปี/เดือน ของงวดจริง (period_year, period_month)
     * ไม่ใช่ fiscal_year + period_month ซึ่งจะทำให้ ธ.ค. 2568 (งวดแรกของปีงบ 2569)
     * กลายเป็นงวดล่าสุดของปีงบ 2569 แทน พ.ค. 2569
     *
     * ใช้ orderBy แทน latestOfMany เพื่อหลีกเลี่ยง ambiguous column
     */
    public function latestPayroll()
    {
        return $this->hasOne(Payroll::class, 'citizen_id', 'citizen_id')
            ->orderByDesc('payrolls.period_year')
            ->orderByDesc('payrolls.period_month')
            ->orderByDesc('payrolls.id')
            ->limit(1);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    // ========== Accessors ==========

    /**
     * full_name = "นายสมชาย ใจดี"
     */
    public function getFullNameAttribute(): string
    {
        $prefix = $this->prefix?->name ?? '';
        return trim("{$prefix}{$this->first_name} {$this->last_name}");
    }

    /**
     * "เงินเดือน" ตามที่ไฟล์เงินเดือนกำหนด = ยอดรวมรายรับทั้งหมด รายบุคคล (total_income)
     * ของงวดล่าสุด — ไม่ใช่คอลัมน์ "เงินเดือน" (salary) ในไฟล์
     *
     * คืน null เมื่อยังไม่มีข้อมูลจากไฟล์ เพื่อให้ UI แสดง "ยังไม่มีข้อมูล" ได้ตรง ๆ
     */
    public function getLatestPayrollIncomeAttribute(): ?float
    {
        $value = $this->latestPayroll?->total_income;

        return $value === null ? null : (float) $value;
    }

    /** ป้ายงวดของเงินเดือนจากไฟล์ เช่น "พฤษภาคม 2569" (null ถ้าไม่รู้งวด) */
    public function getIncomePeriodLabelAttribute(): ?string
    {
        $payroll = $this->latestPayroll;

        if (! $payroll || ! $payroll->period_month) {
            return null;
        }

        $label = ThaiFiscalYear::monthLabel((int) $payroll->period_month);

        return trim($label . ' ' . ($payroll->period_year ?? ''));
    }

    /**
     * เลขที่บัญชีที่ใช้แสดงผล
     *
     * ใช้ค่าจาก payrolls ล่าสุดก่อน (ข้อมูลที่นำเข้าจากการเงิน)
     * ถ้าไม่มี payroll หรือยังว่าง ให้ใช้ค่าที่บันทึกไว้บนตาราง employees แทน
     * (คนที่ไม่มีข้อมูล payroll เช่น เลขบัตรประชาชนไม่ตรง จะได้ไม่ขึ้น "-")
     */
    public function getBankAccountNumberAttribute(): ?string
    {
        $fromPayroll = $this->latestPayroll?->bank_account;

        if ($fromPayroll !== null && $fromPayroll !== '') {
            return $fromPayroll;
        }

        return $this->bank_account !== '' ? $this->bank_account : null;
    }

    // ========== Scopes ==========

    /**
     * ค้นหาจากชื่อ, นามสกุล, เลขบัตร, เลขที่ตำแหน่ง
     */
    public function scopeSearch($query, ?string $term)
    {
        if (!$term) {
            return $query;
        }

        return $query->where(function ($q) use ($term) {
            $q->where('first_name', 'like', "%{$term}%")
                ->orWhere('last_name', 'like', "%{$term}%")
                ->orWhere('citizen_id', 'like', "%{$term}%")
                ->orWhere('position_number', 'like', "%{$term}%");
        });
    }

    /**
     * Filter จาก query string
     */
    public function scopeFilter($query, array $filters)
    {
        return $query
            ->when($filters['employee_type_id'] ?? null, fn($q, $v) => $q->where('employee_type_id', $v))
            ->when($filters['position_id'] ?? null,      fn($q, $v) => $q->where('position_id', $v))
            ->when($filters['duty_id'] ?? null,          fn($q, $v) => $q->where('duty_id', $v))
            ->when($filters['group_id'] ?? null,         fn($q, $v) => $q->where('group_id', $v))
            ->when($filters['work_id'] ?? null,          fn($q, $v) => $q->where('work_id', $v))
            ->when($filters['status_id'] ?? null,        fn($q, $v) => $q->where('status_id', $v));
    }
}
