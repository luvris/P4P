<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

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
        'note',
        'created_by',
        'updated_by',
        // New HR fields
        'hid',
        'sex',
        'blood_type',
        'birth_date',
        'tel',
        'mobile',
        'address1',
        'address2',
        'emergency_contact_prefix',
        'emergency_contact_name',
        'emergency_contact_lname',
        'leaves_by',
        'finger',
        'email',
        'line_token',
    ];

    protected $casts = [
        'salary' => 'decimal:2',
        'birth_date' => 'date',
    ];

    protected $appends = ['full_name'];

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
