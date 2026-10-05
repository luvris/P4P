<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ให้คอลัมน์เพิ่มเติมวางต่อจากคอลัมน์ที่ระบุ ไม่ใช่ต้องต่อท้ายไฟล์เสมอ
 *
 * ภารกิจ/กลุ่มงาน/งาน เป็นข้อมูลของคน ควรอยู่ติดกับเลขบัตรประชาชน
 * ไม่ใช่หลุดไปท้ายไฟล์หลังช่องหมายเหตุ
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payroll_extra_columns')
            || Schema::hasColumn('payroll_extra_columns', 'after_column')) {
            return;
        }

        Schema::table('payroll_extra_columns', function (Blueprint $table) {
            // ชื่อคอลัมน์ในไฟล์ต้นแบบที่ต้องการวางต่อจาก — ว่าง = ต่อท้ายไฟล์
            $table->string('after_column', 100)->nullable()->after('name');
        });

        DB::table('payroll_extra_columns')
            ->whereIn('key', ['duty', 'work_group', 'work'])
            ->update(['after_column' => 'ID CARD']);
    }

    public function down(): void
    {
        if (Schema::hasTable('payroll_extra_columns')
            && Schema::hasColumn('payroll_extra_columns', 'after_column')) {
            Schema::table('payroll_extra_columns', function (Blueprint $table) {
                $table->dropColumn('after_column');
            });
        }
    }
};