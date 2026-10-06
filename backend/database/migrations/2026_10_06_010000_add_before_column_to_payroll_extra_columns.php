<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * เลือกยัดคอลัมน์เพิ่มเติมไว้ "ก่อน" คอลัมน์ที่ต้องการได้
 *
 * เดิมมีแต่ after_column (ต่อจากคอลัมน์ที่ระบุ) ซึ่งยัดไว้ก่อนคอลัมน์แรกของไฟล์ไม่ได้
 * เพราะไม่มีคอลัมน์ก่อนหน้าให้อ้างอิง งานจริงมักอยากได้แบบเจาะจงกลางไฟล์
 * เช่น วางคอลัมน์ใหม่ไว้ระหว่าง "เงินเดือน" กับ "ตกเบิก"
 *
 * ว่าง = ไม่ได้ยึดตำแหน่ง ระบบจะใช้ after_column ต่อไป ถ้าว่างทั้งคู่ก็ต่อท้ายไฟล์
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payroll_extra_columns')
            || Schema::hasColumn('payroll_extra_columns', 'before_column')) {
            return;
        }

        Schema::table('payroll_extra_columns', function (Blueprint $table) {
            // ชื่อคอลัมน์ในไฟล์ต้นแบบที่ต้องการวางไว้ "ก่อน" — ว่าง = ไม่ได้ระบุ
            $table->string('before_column', 100)->nullable()->after('after_column');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('payroll_extra_columns')
            && Schema::hasColumn('payroll_extra_columns', 'before_column')) {
            Schema::table('payroll_extra_columns', function (Blueprint $table) {
                $table->dropColumn('before_column');
            });
        }
    }
};