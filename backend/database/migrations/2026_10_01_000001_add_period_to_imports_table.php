<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * เพิ่มงวด (เดือน/ปีงบ) ให้ชุดข้อมูลที่นำเข้า
 *
 * การคำนวณเงินสำรองแบบรายปีต้องรู้ว่า payroll แต่ละชุดคือเดือนไหนของปีงบประมาณ
 * คอลัมน์ทั้งหมดเป็น nullable เพื่อไม่กระทบข้อมูลเดิม — แถวที่ยังไม่ระบุงวด
 * ระบบจะอนุมานจากวันที่อัปโหลดให้ก่อน
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('imports', function (Blueprint $table) {
            $table->unsignedSmallInteger('fiscal_year')->nullable()->after('import_type')
                ->comment('ปีงบประมาณ (พ.ศ.) ของงวดข้อมูลนี้');
            $table->unsignedTinyInteger('period_month')->nullable()->after('fiscal_year')
                ->comment('เดือนของงวด 1-12 (ต.ค.-ธ.ค. = ปีปฏิทินก่อนปีงบ)');
            $table->unsignedSmallInteger('period_year')->nullable()->after('period_month')
                ->comment('ปีปฏิทิน (พ.ศ.) ของงวด');

            $table->index(['fiscal_year', 'period_month']);
        });
    }

    public function down(): void
    {
        Schema::table('imports', function (Blueprint $table) {
            $table->dropIndex(['fiscal_year', 'period_month']);
            $table->dropColumn(['fiscal_year', 'period_month', 'period_year']);
        });
    }
};
