<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('imports', function (Blueprint $table) {
            $table->string('import_type', 20)->default('payroll')->after('id')
                ->comment('ประเภทการนำเข้า: payroll, hr');
            $table->integer('inserted_rows')->default(0)->after('duplicate_rows')
                ->comment('จำนวนแถวที่เพิ่มใหม่ (HR import)');
            $table->integer('updated_rows')->default(0)->after('inserted_rows')
                ->comment('จำนวนแถวที่อัปเดต (HR import)');
            $table->integer('skipped_rows')->default(0)->after('updated_rows')
                ->comment('จำนวนแถวที่ข้าม (HR import)');
        });
    }

    public function down(): void
    {
        Schema::table('imports', function (Blueprint $table) {
            $table->dropColumn(['import_type', 'inserted_rows', 'updated_rows', 'skipped_rows']);
        });
    }
};