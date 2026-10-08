<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * กรอบวงเงิน P4P ที่บันทึกไว้ แยกตามปีงบประมาณ
 *
 * เก็บ snapshot ของผลการคำนวณ (ค่าแรง, วงเงิน Activity/Quality, ตารางกลุ่มวิชาชีพ)
 * เพื่อให้ย้อนดู/พิมพ์เอกสารที่อนุมัติแล้วได้ตรงกับตอนบันทึก
 */
class BudgetFramework extends Model
{
    use HasFactory;

    protected $fillable = [
        'fiscal_year',
        'cost_basis',
        'as_of_month',
        'months_present',
        'labor_cost_monthly',
        'labor_cost_annual',
        'labor_cost_to_date',
        'labor_percent',
        'activity_ratio',
        'quality_ratio',
        'p4p_annual',
        'activity_budget',
        'quality_budget',
        'total_headcount',
        'total_weight',
        'total_weighted',
        'unit_rate_year',
        'unit_rate_month',
        'groups',
        'note',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'fiscal_year' => 'integer',
        'as_of_month' => 'integer',
        'months_present' => 'integer',
        'labor_cost_monthly' => 'decimal:2',
        'labor_cost_annual' => 'decimal:2',
        'labor_cost_to_date' => 'decimal:2',
        'labor_percent' => 'decimal:2',
        'activity_ratio' => 'decimal:2',
        'quality_ratio' => 'decimal:2',
        'p4p_annual' => 'decimal:2',
        'activity_budget' => 'decimal:2',
        'quality_budget' => 'decimal:2',
        'total_headcount' => 'integer',
        'total_weight' => 'decimal:2',
        'total_weighted' => 'decimal:2',
        'unit_rate_year' => 'decimal:4',
        'unit_rate_month' => 'decimal:4',
        'groups' => 'array',
    ];

    // ========== Relationships ==========

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
