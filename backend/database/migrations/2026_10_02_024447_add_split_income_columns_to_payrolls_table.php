<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * แยกคอลัมน์ P4P และ ปกส. ออกเป็นรายการย่อย
 *
 * ไฟล์ payroll รูปแบบใหม่มี P4P 2 คอลัมน์ (ประจำเดือน / โครงการคุณภาพ)
 * และ ปกส. 2 คอลัมน์ (นายจ้าง / ผู้ประกันตน) — HR ต้องการเก็บแยกไม่รวมกัน
 * ส่วน p4p_income / social_security เดิมคงไว้เป็น "ผลรวม"
 * เพื่อไม่ให้ฐานคำนวณเงินสำรอง (ReserveFundController) ต้องแก้
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payrolls', function (Blueprint $table) {
            $table->decimal('p4p_monthly', 12, 2)->default(0)->after('p4p_income')
                ->comment('P4P ประจำเดือน');
            $table->decimal('p4p_quality_project', 12, 2)->default(0)->after('p4p_monthly')
                ->comment('P4P โครงการคุณภาพ');
            $table->decimal('social_security_employer', 12, 2)->default(0)->after('social_security')
                ->comment('ปกส. นายจ้าง');
            $table->decimal('social_security_employee', 12, 2)->default(0)->after('social_security_employer')
                ->comment('ปกส. ผู้ประกันตน');
        });
    }

    public function down(): void
    {
        Schema::table('payrolls', function (Blueprint $table) {
            $table->dropColumn([
                'p4p_monthly',
                'p4p_quality_project',
                'social_security_employer',
                'social_security_employee',
            ]);
        });
    }
};
