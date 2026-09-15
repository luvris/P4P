<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();

            //Natural key — ใช้เชื่อมกับ payrolls
            $table->string('citizen_id', 20)->unique();

            // ข้อมูลส่วนตัว
            $table->foreignId('prefix_id')->nullable()->constrained('prefixes')->nullOnDelete();
            $table->string('first_name', 255);
            $table->string('last_name', 255);

            // ข้อมูลการทำงาน
            $table->string('position_number', 50)->nullable();
            $table->decimal('salary', 12, 2)->nullable();
            $table->foreignId('employee_type_id')->nullable()->constrained('employee_types')->nullOnDelete();
            $table->foreignId('position_id')->nullable()->constrained('positions')->nullOnDelete();
            $table->foreignId('duty_id')->nullable()->constrained('duties')->nullOnDelete();
            $table->foreignId('group_id')->nullable()->constrained('groups')->nullOnDelete();
            $table->foreignId('work_id')->nullable()->constrained('works')->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('status_id')->nullable()->constrained('employee_statuses')->nullOnDelete();
            $table->string('bank_account', 30)->nullable();

            // หมายเหตุ
            $table->text('note')->nullable();

            // Audit
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            // Index สำหรับ filter
            $table->index(['employee_type_id', 'status_id']);
            $table->index('first_name');
            $table->index('last_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
