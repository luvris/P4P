<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ตารางเก็บกรอบวงเงิน P4P ที่บันทึกไว้ แยกตามปีงบประมาณ
 *
 * เป็นตารางใหม่ทั้งหมด ไม่แก้ไขตารางหรือคอลัมน์เดิม
 * unique(fiscal_year) — 1 ปีงบประมาณเก็บได้ 1 กรอบ (บันทึกซ้ำ = อัปเดต)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budget_frameworks', function (Blueprint $table) {
            $table->id();

            $table->unsignedSmallInteger('fiscal_year')->unique()
                ->comment('ปีงบประมาณ (พ.ศ.)');

            $table->string('cost_basis', 30)->default('total_income')
                ->comment('ฐานค่าแรง: total_income = ยอดรวมรายรับทั้งหมด, salary = เงินเดือน');

            $table->unsignedTinyInteger('as_of_month')->nullable()
                ->comment('งวด (เดือน) ล่าสุดที่มีข้อมูล — ใช้พิมพ์ "ค่าแรง ณ วันที่"');
            $table->unsignedTinyInteger('months_present')->default(0)
                ->comment('จำนวนงวดที่มีข้อมูล (เต็ม 12)');

            $table->decimal('labor_cost_monthly', 15, 2)->default(0)->comment('ค่าแรงต่อเดือน (เฉลี่ย)');
            $table->decimal('labor_cost_annual', 15, 2)->default(0)->comment('ค่าแรงต่อปี');
            $table->decimal('labor_cost_to_date', 15, 2)->default(0)->comment('ค่าแรงสะสมถึงงวดล่าสุด');

            $table->decimal('labor_percent', 5, 2)->default(3)->comment('ร้อยละของค่าแรงที่จ่ายเป็น P4P');
            $table->decimal('activity_ratio', 5, 2)->default(70)->comment('สัดส่วนตามปริมาณงาน (Activity) %');
            $table->decimal('quality_ratio', 5, 2)->default(30)->comment('สัดส่วนเพื่อการพัฒนาคุณภาพ (Quality) %');

            $table->decimal('p4p_annual', 15, 2)->default(0)->comment('จ่าย P4P ต่อปี');
            $table->decimal('activity_budget', 15, 2)->default(0)->comment('วงเงินตามปริมาณงาน');
            $table->decimal('quality_budget', 15, 2)->default(0)->comment('วงเงินเพื่อการพัฒนาคุณภาพ');

            $table->unsignedInteger('total_headcount')->default(0)->comment('จำนวนคนรวม');
            $table->decimal('total_weight', 12, 2)->default(0)->comment('ผลรวมสัดส่วน (คน)');
            $table->decimal('total_weighted', 12, 2)->default(0)->comment('ผลรวมสัดส่วนถ่วงน้ำหนัก (คน*คน)');
            $table->decimal('unit_rate_year', 15, 4)->default(0)->comment('เงิน P4P ต่อหน่วย (KPI) ต่อปี');
            $table->decimal('unit_rate_month', 15, 4)->default(0)->comment('เงิน P4P ต่อหน่วย (KPI) ต่อเดือน');

            $table->json('groups')->nullable()->comment('snapshot ตารางกลุ่มวิชาชีพ (จำนวนคน/สัดส่วน/ยอดเงิน)');

            $table->text('note')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_frameworks');
    }
};
