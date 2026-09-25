<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class EmploymentHistory extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'employee_id',
        'serial_number',
        'wid',
        'employee_type',
        'work_id',
        'manager',
        'position',
        'class',
        'condition',
        'start_date',
        'end_date',
        'experience',
        'mark',
        'status',
        'payroll',
        'appointment_date',
        'appointment_code',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'appointment_date' => 'date',
        'payroll' => 'decimal:2',
    ];

    // ========== Relationships ==========

    /**
     * เชื่อมกับ Employee ผ่าน employee_id
     */
    public function employee()
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
