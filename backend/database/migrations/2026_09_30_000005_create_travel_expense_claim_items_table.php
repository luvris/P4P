<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * รายการผู้เบิกในใบเบิกค่าใช้จ่ายเดินทางไปราชการ
 *
 * employee_id อ้างถึงตาราง employees เดิม (nullable — ลบบุคลากรแล้วรายการยังอยู่)
 * pid / first_name / last_name เก็บสำเนาไว้ เพื่อให้เอกสารที่ยืนยันแล้วคงข้อมูลเดิม
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('travel_expense_claim_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('travel_expense_claim_id')
                ->constrained('travel_expense_claims')
                ->cascadeOnDelete();

            $table->foreignId('employee_id')->nullable()
                ->constrained('employees')
                ->nullOnDelete()
                ->comment('บุคลากรจากตาราง employees');

            $table->string('pid', 50)->nullable()->comment('รหัสบุคลากร (employees.employee_id)');
            $table->string('first_name');
            $table->string('last_name');
            $table->string('position_name')->nullable()->comment('ตำแหน่ง ณ เวลาที่บันทึก');

            $table->decimal('allowance_amount', 12, 2)->default(0)->comment('ค่าเบี้ยเลี้ยง');
            $table->decimal('accommodation_amount', 12, 2)->default(0)->comment('ค่าที่พัก');
            $table->decimal('transportation_amount', 12, 2)->default(0)->comment('ค่าพาหนะ');
            $table->decimal('other_amount', 12, 2)->default(0)->comment('ค่าใช้จ่ายอื่น');
            $table->decimal('total_amount', 12, 2)->default(0)->comment('รวมเงิน (คำนวณฝั่ง backend)');

            $table->unsignedSmallInteger('sort_order')->default(0)->comment('ลำดับในเอกสาร');

            $table->timestamps();

            // กันบุคลากรซ้ำในเอกสารเดียวกัน
            $table->unique(['travel_expense_claim_id', 'employee_id'], 'tec_items_claim_employee_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('travel_expense_claim_items');
    }
};
