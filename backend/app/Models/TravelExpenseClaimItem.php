<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * รายการผู้เบิกในใบเบิกค่าใช้จ่ายเดินทางไปราชการ
 */
class TravelExpenseClaimItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'travel_expense_claim_id',
        'employee_id',
        'pid',
        'first_name',
        'last_name',
        'position_name',
        'allowance_amount',
        'accommodation_amount',
        'transportation_amount',
        'other_amount',
        'total_amount',
        'sort_order',
    ];

    protected $casts = [
        'allowance_amount'      => 'decimal:2',
        'accommodation_amount'  => 'decimal:2',
        'transportation_amount' => 'decimal:2',
        'other_amount'          => 'decimal:2',
        'total_amount'          => 'decimal:2',
        'sort_order'            => 'integer',
    ];

    // ========== Relationships ==========

    public function claim()
    {
        return $this->belongsTo(TravelExpenseClaim::class, 'travel_expense_claim_id');
    }

    /** บุคลากรจากระบบเดิม (nullable) */
    public function employee()
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    /**
     * รวมเงินของรายการ — คำนวณฝั่ง backend เท่านั้น
     */
    public static function computeTotal(array $row): float
    {
        return round(
            (float) ($row['allowance_amount'] ?? 0)
            + (float) ($row['accommodation_amount'] ?? 0)
            + (float) ($row['transportation_amount'] ?? 0)
            + (float) ($row['other_amount'] ?? 0),
            2
        );
    }
}
