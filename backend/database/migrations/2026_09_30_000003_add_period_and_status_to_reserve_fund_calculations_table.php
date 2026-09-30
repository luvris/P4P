<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * เพิ่มงวด (เดือน) และสถานะยืนยัน ให้ผลการคำนวณเงินสำรอง
 *
 * - period_month / period_year = งวด payroll ที่ผลการคำนวณนี้อ้างถึง
 * - status draft/confirmed — ยอดสะสมของปีงบจะรวมเฉพาะงวดที่ confirmed
 * - เปลี่ยน unique เป็น (fiscal_year, period_month) เพื่อกันนับงวดเดียวกันซ้ำ
 *
 * เพิ่มคอลัมน์ใหม่ทั้งหมด ไม่แก้คอลัมน์เดิม และไม่ลบข้อมูลเดิม
 * แถวเดิมจะมี period_month = null และ status = 'draft' จนผู้ใช้บันทึกใหม่
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reserve_fund_calculations', function (Blueprint $table) {
            $table->unsignedTinyInteger('period_month')->nullable()->after('import_id')
                ->comment('งวดเดือน 1-12 (ต.ค.-ธ.ค. = ปีปฏิทินก่อนปีงบ)');
            $table->unsignedSmallInteger('period_year')->nullable()->after('period_month')
                ->comment('ปีปฏิทินของงวด (พ.ศ.)');

            $table->string('status', 20)->default('draft')->after('total_employees')
                ->comment('draft = ร่าง, confirmed = ยืนยันแล้ว (นับในยอดสะสม)');
            $table->timestamp('confirmed_at')->nullable()->after('status');
            $table->foreignId('confirmed_by')->nullable()->after('confirmed_at')
                ->constrained('users')->nullOnDelete();
        });

        Schema::table('reserve_fund_calculations', function (Blueprint $table) {
            // 1 ปีงบ + 1 งวด เก็บได้ 1 รายการ (บันทึกซ้ำ = อัปเดต ไม่นับซ้ำในยอดสะสม)
            $table->dropUnique(['fiscal_year', 'import_id']);
            $table->unique(['fiscal_year', 'period_month']);
            $table->index(['fiscal_year', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('reserve_fund_calculations', function (Blueprint $table) {
            $table->dropIndex(['fiscal_year', 'status']);
            $table->dropUnique(['fiscal_year', 'period_month']);
            $table->unique(['fiscal_year', 'import_id']);
        });

        Schema::table('reserve_fund_calculations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('confirmed_by');
            $table->dropColumn(['period_month', 'period_year', 'status', 'confirmed_at']);
        });
    }
};
