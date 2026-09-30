<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * เพิ่มคอลัมน์รายรับที่ใช้เป็นฐานคำนวณเงินสำรอง (ล่วงเวลา, เงินประจำตำแหน่ง, P4P)
 *
 * เป็นการเพิ่มคอลัมน์ใหม่ทั้งหมด (default 0) ไม่แก้ไขคอลัมน์เดิม
 * ข้อมูล payroll ที่นำเข้าไปแล้วจะมีค่า 0 จนกว่าจะนำเข้าไฟล์ใหม่
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payrolls', function (Blueprint $table) {
            $table->decimal('overtime', 12, 2)->default(0)->after('living_allowance')
                ->comment('ล่วงเวลา (OT)');
            $table->decimal('position_allowance', 12, 2)->default(0)->after('overtime')
                ->comment('เงินประจำตำแหน่ง (พตส.)');
            $table->decimal('p4p_income', 12, 2)->default(0)->after('position_allowance')
                ->comment('เงิน P4P');
        });
    }

    public function down(): void
    {
        Schema::table('payrolls', function (Blueprint $table) {
            $table->dropColumn(['overtime', 'position_allowance', 'p4p_income']);
        });
    }
};
