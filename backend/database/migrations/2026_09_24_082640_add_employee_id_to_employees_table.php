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
        Schema::table('employees', function (Blueprint $table) {
            // PID - รหัสพนักงานภายใน (ใช้เชื่อมกับตาราง work)
            $table->string('employee_id', 50)->nullable()->after('id')->comment('รหัสพนักงานภายใน (PID)')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('employee_id');
        });
    }
};
