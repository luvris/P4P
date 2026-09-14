<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Payroll extends Model
{
    use HasFactory;

    protected $fillable = [
        'import_id',
        'employee_type',
        'first_name',
        'last_name',
        'citizen_id',
        'bank_account',
        'salary',
        'living_allowance',
        'total_income',
        'social_security',
        'electricity',
        'water',
        'health_insurance',
        'cooperative',
        'life_insurance',
        'provident_fund',
        'student_loan',
        'total_deduction',
        'net_income',
    ];

    protected $casts = [
        'salary' => 'decimal:2',
        'living_allowance' => 'decimal:2',
        'total_income' => 'decimal:2',
        'social_security' => 'decimal:2',
        'electricity' => 'decimal:2',
        'water' => 'decimal:2',
        'health_insurance' => 'decimal:2',
        'cooperative' => 'decimal:2',
        'life_insurance' => 'decimal:2',
        'provident_fund' => 'decimal:2',
        'student_loan' => 'decimal:2',
        'total_deduction' => 'decimal:2',
        'net_income' => 'decimal:2',
    ];

    public function import()
    {
        return $this->belongsTo(Import::class);
    }

    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }
}
