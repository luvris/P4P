<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salary_adjustments', function (Blueprint $table) {
            $table->id();

            // ต้นทางเป็นพนักงานที่ถูกปรับฐานเงินเดือน
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();

            // เงินเดือนเดิม
            $table->decimal('old_salary', 12, 2)->default(0);

            // เงินเดือนใหม่
            $table->decimal('new_salary', 12, 2)->default(0);

            // ปรับเพิ่มเท่าไหร่ (คำนวณ/บันทึกได้จาก new - old)
            $table->decimal('increase_amount', 12, 2)->default(0);

            // % ที่ปรับเพิ่ม (เก็บไว้เพื่อความสะดวกในการรายงาน)
            $table->decimal('increase_percent', 6, 2)->nullable();

            // วันที่ปรับฐานเงินเดือน
            $table->date('adjustment_date');

            // ประเภทการปรับ เช่น ครบ 6 เดือน / ครบ 1 ปี / อื่นๆ
            $table->string('adjustment_type', 50)->nullable();

            // หมายเหตุ
            $table->text('note')->nullable();

            // Audit
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            // Index สำหรับ filter / รายงาน
            $table->index('employee_id');
            $table->index('adjustment_date');
            $table->index('adjustment_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salary_adjustments');
    }
};