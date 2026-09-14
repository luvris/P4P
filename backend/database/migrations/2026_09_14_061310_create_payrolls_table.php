<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payrolls', function (Blueprint $table) {
            $table->id();
            
            // Foreign keys
            $table->foreignId('import_id')->nullable()->constrained('imports')->onDelete('set null');
            
            // ข้อมูลพนักงาน
            $table->string('employee_type')->nullable()->comment('ประเภท: ข้าราชการ, ลูกจ้าง');
            $table->string('first_name')->nullable()->comment('ชื่อ');
            $table->string('last_name')->nullable()->comment('นามสกุล');
            $table->string('bank_account', 30)->nullable()->comment('เลขที่บัญชี');
            
            // รายรับ
            $table->decimal('salary', 12, 2)->default(0)->comment('เงินเดือน');
            $table->decimal('living_allowance', 12, 2)->default(0)->comment('ครองชีพ');
            $table->decimal('total_income', 12, 2)->default(0)->comment('รวมรายรับ');
            
            // รายจ่าย
            $table->decimal('social_security', 12, 2)->default(0)->comment('ปกส.');
            $table->decimal('electricity', 12, 2)->default(0)->comment('ไฟ');
            $table->decimal('water', 12, 2)->default(0)->comment('น้ำ');
            $table->decimal('health_insurance', 12, 2)->default(0)->comment('สสจ.');
            $table->decimal('cooperative', 12, 2)->default(0)->comment('ธ.สงเคราะห์');
            $table->decimal('life_insurance', 12, 2)->default(0)->comment('ฌกส.');
            $table->decimal('provident_fund', 12, 2)->default(0)->comment('กองทุนสำรอง');
            $table->decimal('student_loan', 12, 2)->default(0)->comment('กยศ.');
            $table->decimal('total_deduction', 12, 2)->default(0)->comment('รวมรายจ่าย');
            
            // รับจริง
            $table->decimal('net_income', 12, 2)->default(0)->comment('รับจริง');
            
            $table->timestamps();
            
            // Index สำหรับการค้นหา
            $table->index('first_name');
            $table->index('last_name');
            $table->index('bank_account');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payrolls');
    }
};