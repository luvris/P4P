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
            // เปลี่ยนชื่อ column จาก emergency_contact_* เป็น english_*
            $table->renameColumn('emergency_contact_prefix', 'english_prefix');
            $table->renameColumn('emergency_contact_name', 'english_first_name');
            $table->renameColumn('emergency_contact_lname', 'english_last_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            // ย้อนกลับถ้า rollback
            $table->renameColumn('english_prefix', 'emergency_contact_prefix');
            $table->renameColumn('english_first_name', 'emergency_contact_name');
            $table->renameColumn('english_last_name', 'emergency_contact_lname');
        });
    }
};
