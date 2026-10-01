<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * เปลี่ยนชื่อประเภทค่าใช้จ่ายของใบเบิกค่าใช้จ่ายเดินทาง
 *
 * เดิม: "ค่าเบี้ยเลี้ยง ค่าที่พัก ค่าพาหนะ ภายในประเทศ" / "... ต่างประเทศ"
 * ใหม่: "เดินทางไปราชการ" / "เดินทางไปราชการโดยฝึกอบรม"
 *
 * ข้อมูลเดิมถูกแปลงตามคู่ที่ใกล้เคียงที่สุด (ในประเทศ = เดินทางไปราชการ)
 */
return new class extends Migration
{
    protected const OLD_DOMESTIC = 'ค่าเบี้ยเลี้ยง ค่าที่พัก ค่าพาหนะ ภายในประเทศ';
    protected const OLD_OVERSEAS = 'ค่าเบี้ยเลี้ยง ค่าที่พัก ค่าพาหนะ ต่างประเทศ';

    protected const NEW_TRAVEL = 'เดินทางไปราชการ';
    protected const NEW_TRAINING = 'เดินทางไปราชการโดยฝึกอบรม';

    public function up(): void
    {
        DB::table('travel_expense_claims')
            ->where('expense_category', self::OLD_DOMESTIC)
            ->update(['expense_category' => self::NEW_TRAVEL]);

        DB::table('travel_expense_claims')
            ->where('expense_category', self::OLD_OVERSEAS)
            ->update(['expense_category' => self::NEW_TRAINING]);
    }

    public function down(): void
    {
        DB::table('travel_expense_claims')
            ->where('expense_category', self::NEW_TRAVEL)
            ->update(['expense_category' => self::OLD_DOMESTIC]);

        DB::table('travel_expense_claims')
            ->where('expense_category', self::NEW_TRAINING)
            ->update(['expense_category' => self::OLD_OVERSEAS]);
    }
};
