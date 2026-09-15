<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Drop FK + column ออกจาก employees
        Schema::table('employees', function (Blueprint $table) {
            if (Schema::hasColumn('employees', 'department_id')) {
                $table->dropForeign(['department_id']);
                $table->dropColumn('department_id');
            }
        });

        // 2. Drop table departments
        Schema::dropIfExists('departments');
    }

    public function down(): void
    {
        // สร้างกลับ (เผื่อ rollback)
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255)->unique();
            $table->timestamps();
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->foreignId('department_id')
                ->nullable()
                ->after('status_id')
                ->constrained('departments')
                ->nullOnDelete();
        });
    }
};
