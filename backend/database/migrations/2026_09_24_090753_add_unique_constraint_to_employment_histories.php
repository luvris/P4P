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
        Schema::table('employment_histories', function (Blueprint $table) {
            // เพิ่ม unique constraint สำหรับ employee_id + serial_number
            // ป้องกันข้อมูลซ้ำของพนักงานคนเดียวกันในลำดับเดียวกัน
            $table->unique(['employee_id', 'serial_number'], 'unique_employee_serial');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employment_histories', function (Blueprint $table) {
            $table->dropUnique('unique_employee_serial');
        });
    }
};
