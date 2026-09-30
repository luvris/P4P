<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ใบเบิกค่าใช้จ่ายเดินทางไปราชการ (หัวเอกสาร)
 *
 * ตารางใหม่ทั้งหมด ไม่กระทบตาราง payrolls / imports / employees เดิม
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('travel_expense_claims', function (Blueprint $table) {
            $table->id();

            $table->string('document_no', 50)->nullable()->unique()
                ->comment('เลขที่เอกสาร เช่น TEC-2569-0001');

            $table->unsignedSmallInteger('fiscal_year')
                ->comment('ปีงบประมาณ (พ.ศ.) — มาจากตัวเลือกบน Header');

            $table->date('claim_period')
                ->comment('เดือนที่เบิก เก็บเป็นวันที่จริง (วันที่ 1 ของเดือน)');

            $table->string('expense_category')
                ->comment('ประเภทค่าใช้จ่าย เช่น ค่าเบี้ยเลี้ยง ค่าที่พัก ค่าพาหนะ ภายในประเทศ');

            $table->string('organization_name')->nullable()->comment('ชื่อหน่วยงาน');
            $table->text('note')->nullable();

            $table->string('status', 20)->default('draft')
                ->comment('draft = ร่าง, confirmed = ยืนยันแล้ว, cancelled = ยกเลิก');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();

            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();

            $table->timestamps();

            $table->index(['fiscal_year', 'status']);
            $table->index('claim_period');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('travel_expense_claims');
    }
};
