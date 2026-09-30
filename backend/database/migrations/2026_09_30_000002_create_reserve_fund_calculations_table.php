<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ตารางเก็บผลการคำนวณเงินสำรอง แยกตามปีงบประมาณ
 *
 * ตารางใหม่ ไม่กระทบ schema เดิม
 * unique(fiscal_year, import_id) — 1 ปีงบ + 1 ชุด payroll เก็บได้ 1 รายการ (บันทึกซ้ำ = อัปเดต)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reserve_fund_calculations', function (Blueprint $table) {
            $table->id();

            $table->unsignedSmallInteger('fiscal_year')->comment('ปีงบประมาณ (พ.ศ.)');
            $table->foreignId('import_id')->nullable()->constrained('imports')->nullOnDelete()
                ->comment('ชุดข้อมูล payroll ที่ใช้คำนวณ');

            $table->decimal('percent', 5, 2)->comment('เปอร์เซ็นต์ที่ใช้คำนวณ');
            $table->decimal('total_income_base', 15, 2)->default(0)
                ->comment('ฐานรายรับรวม = เงินเดือน + OT + เงินประจำตำแหน่ง + P4P');
            $table->decimal('total_reserve', 15, 2)->default(0)->comment('เงินสำรองรวม');
            $table->unsignedInteger('total_employees')->default(0)->comment('จำนวนบุคลากร');

            $table->json('income_breakdown')->nullable()->comment('ยอดแยกตามประเภทรายรับ');
            $table->json('duty_breakdown')->nullable()->comment('ยอดแยกตามภารกิจ/กลุ่มงาน/งาน');

            $table->text('note')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['fiscal_year', 'import_id']);
            $table->index('fiscal_year');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reserve_fund_calculations');
    }
};
