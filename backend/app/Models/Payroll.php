<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Payroll extends Model
{
    use HasFactory;

    protected $fillable = [
        'import_id',
        'fiscal_year',
        'period_month',
        'period_year',
        'seq_number',
        'employee_type',
        'prefix',
        'first_name',
        'last_name',
        'position_name',
        'position_number',
        'citizen_id',
        'bank_account',
        'salary',
        'salary_deduction',
        'project_budget',
        'regular_allowance',
        'living_allowance',
        'no_medical_service',
        'position_allowance',
        'overtime',
        'night_shift_budget',
        'night_shift_maintenance',
        'p4p_income',
        'p4p_monthly',
        'p4p_quality_project',
        'covid_allowance',
        'other_income',
        'total_direct_income',
        'total_income',
        'total_indirect_income',
        'medical_treatment',
        'education_allowance',
        'meal_allowance',
        'housing_allowance',
        'transport_allowance',
        'other_expenses',
        'project_cost',
        'social_security',
        'social_security_employer',
        'social_security_employee',
        'electricity',
        'water',
        'health_insurance',
        'cooperative',
        'life_insurance',
        'provident_fund',
        'student_loan',
        'total_deduction',
        'net_income',
        'note',
        'extra_data',
    ];

    protected $casts = [
        'seq_number'          => 'integer',
        'extra_data'          => 'array',
        'period_month'        => 'integer',
        'period_year'         => 'integer',
        'fiscal_year'         => 'integer',
        'salary'           => 'decimal:2',
        'salary_deduction' => 'decimal:2',
        'project_budget'   => 'decimal:2',
        'regular_allowance' => 'decimal:2',
        'living_allowance' => 'decimal:2',
        'no_medical_service' => 'decimal:2',
        'overtime'           => 'decimal:2',
        'position_allowance' => 'decimal:2',
        'night_shift_budget' => 'decimal:2',
        'night_shift_maintenance' => 'decimal:2',
        'p4p_income'         => 'decimal:2',
        'p4p_monthly'      => 'decimal:2',
        'p4p_quality_project' => 'decimal:2',
        'covid_allowance'  => 'decimal:2',
        'other_income'     => 'decimal:2',
        'total_direct_income' => 'decimal:2',
        'total_income'     => 'decimal:2',
        'total_indirect_income' => 'decimal:2',
        'medical_treatment' => 'decimal:2',
        'education_allowance' => 'decimal:2',
        'meal_allowance'   => 'decimal:2',
        'housing_allowance' => 'decimal:2',
        'transport_allowance' => 'decimal:2',
        'other_expenses'   => 'decimal:2',
        'project_cost'     => 'decimal:2',
        'social_security'  => 'decimal:2',
        'social_security_employer' => 'decimal:2',
        'social_security_employee' => 'decimal:2',
        'electricity'      => 'decimal:2',
        'water'            => 'decimal:2',
        'health_insurance' => 'decimal:2',
        'cooperative'      => 'decimal:2',
        'life_insurance'   => 'decimal:2',
        'provident_fund'   => 'decimal:2',
        'student_loan'     => 'decimal:2',
        'total_deduction'  => 'decimal:2',
        'net_income'       => 'decimal:2',
    ];

    // ========== Relationships ==========

    public function import()
    {
        return $this->belongsTo(Import::class);
    }

    /**
     *เชื่อมกับ Employee ผ่าน citizen_id
     */
    public function employee()
    {
        return $this->belongsTo(Employee::class, 'citizen_id', 'citizen_id');
    }
}
