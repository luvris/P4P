<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SalaryAdjustment extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'employee_id',
        'old_salary',
        'new_salary',
        'increase_amount',
        'increase_percent',
        'adjustment_date',
        'adjustment_type',
        'note',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'old_salary' => 'decimal:2',
        'new_salary' => 'decimal:2',
        'increase_amount' => 'decimal:2',
        'increase_percent' => 'decimal:2',
        'adjustment_date' => 'date',
    ];

    // ========== Relationships ==========

    public function employee()
    {
        return $this->belongsTo(Employee::class);
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