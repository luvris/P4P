<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('employment_histories', function (Blueprint $table) {
            $table->id();
            
            // Employee relationship
            $table->foreignId('employee_id')->constrained('employees')->onDelete('cascade')->comment('รหัสพนักงาน');
            
            // SER - Serial number (ลำดับการจ้างงาน)
            $table->string('serial_number', 50)->nullable()->comment('เลขที่ลำดับ');
            
            // WID - Work ID
            $table->string('wid', 50)->nullable()->comment('รหัสงาน');
            
            // EMPLOYEE - ชื่อพนักงาน (เก็บไว้เผื่อใช้อ้างอิง)
            $table->string('employee_name', 255)->nullable()->comment('ชื่อพนักงาน');
            
            // WORKID - รหัสงาน (อาจซ้ำกับ WID)
            $table->string('work_id', 50)->nullable()->comment('รหัสงาน');
            
            // MANAGE - ผู้จัดการ/หัวหน้างาน
            $table->string('manager', 255)->nullable()->comment('ผู้จัดการ/หัวหน้างาน');
            
            // POSITION - ตำแหน่ง
            $table->string('position', 255)->nullable()->comment('ตำแหน่ง');
            
            // CLASS - ชั้น/ระดับ
            $table->string('class', 100)->nullable()->comment('ชั้น/ระดับ');
            
            // CONDITIO - เงื่อนไขการจ้าง
            $table->string('condition', 255)->nullable()->comment('เงื่อนไขการจ้าง');
            
            // DATES - วันที่เริ่มงาน
            $table->date('start_date')->nullable()->comment('วันที่เริ่มงาน');
            
            // DATEE - วันที่สิ้นสุด
            $table->date('end_date')->nullable()->comment('วันที่สิ้นสุด');
            
            // EXP - ประสบการณ์/ระยะเวลา
            $table->string('experience', 100)->nullable()->comment('ประสบการณ์/ระยะเวลา');
            
            // MARK - เครื่องหมาย/หมายเหตุ
            $table->string('mark', 255)->nullable()->comment('เครื่องหมาย/หมายเหตุ');
            
            // PAYROLL - เงินเดือน
            $table->decimal('payroll', 12, 2)->nullable()->comment('เงินเดือน');
            
            // DATEDIRE - วันที่แต่งตั้ง
            $table->date('appointment_date')->nullable()->comment('วันที่แต่งตั้ง');
            
            // CODEDIRE - รหัสการแต่งตั้ง
            $table->string('appointment_code', 100)->nullable()->comment('รหัสการแต่งตั้ง');
            
            // Audit
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            
            $table->timestamps();
            $table->softDeletes();
            
            // Index
            $table->index('employee_id');
            $table->index('serial_number');
            $table->index(['start_date', 'end_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employment_histories');
    }
};
