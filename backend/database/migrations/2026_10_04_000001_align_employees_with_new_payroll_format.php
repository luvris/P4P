<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ปรับตาราง employees ให้ตรงกับคอลัมน์ของไฟล์ payroll รูปแบบใหม่
 *
 * ไฟล์รูปแบบใหม่มีคอลัมน์ด้านบุคลากร:
 *   คำนำหน้า | ชื่อ | นามสกุล | ประเภท | ตำแหน่ง | ตำแหน่งเลขที่
 *   ID CARD | เลขที่บัญชี | เลขที่บัญชี.1
 *
 * ตารางเดิมมีครบเกือบทั้งหมดแล้ว (citizen_id, prefix_id, first_name, last_name,
 * employee_type_id, position_id, position_number, bank_account) ที่ขาดคือ
 * เลขบัญชีสำรอง (เลขที่บัญชี.1) กับเงินเดือนล่าสุดที่อ่านมาจากไฟล์
 *
 * หมายเหตุ: ใช้คอลัมน์ "เลขที่บัญชี.1" เก็บแยกจาก bank_account เพราะในไฟล์จริง
 * ทั้งสองคอลัมน์อาจไม่ใช่ค่าเดียวกัน (เช่น บัญชีเงินสด vs บัญชีโอน)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            // เลขที่บัญชีสำรอง (คอลัมน์ "เลขที่บัญชี.1" ในไฟล์)
            $table->string('bank_account_2', 30)->nullable()->after('bank_account')
                ->comment('เลขที่บัญชีสำรอง (เลขที่บัญชี.1)');

            // เงินเดือนที่ปรากฏในไฟล์ล่าสุด — ใช้เป็นฐานตอนคำนวณการปรับเงินเดือน
            $table->decimal('latest_salary', 12, 2)->nullable()->after('salary')
                ->comment('เงินเดือนล่าสุดที่อ่านจากไฟล์เงินเดือนรูปแบบใหม่');

            // งวดของเงินเดือนล่าสุด เพื่อรู้ว่าข้อมูลเก่าแค่ไหน
            $table->unsignedSmallInteger('latest_period_year')->nullable()->after('latest_salary')
                ->comment('ปีงบประมาณของเงินเดือนล่าสุด');
            $table->unsignedTinyInteger('latest_period_month')->nullable()->after('latest_period_year')
                ->comment('งวดเดือนของเงินเดือนล่าสุด');

            $table->index('bank_account_2');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropIndex(['bank_account_2']);
            $table->dropColumn([
                'bank_account_2',
                'latest_salary',
                'latest_period_year',
                'latest_period_month',
            ]);
        });
    }
};