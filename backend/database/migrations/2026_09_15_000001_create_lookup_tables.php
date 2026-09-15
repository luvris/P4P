<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. prefixes (คำนำหน้า)
        Schema::create('prefixes', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50)->unique();
            $table->string('short_name', 20)->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        // 2. employee_types (ประเภทบุคลากร)
        Schema::create('employee_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        // 3. positions (ตำแหน่ง)
        Schema::create('positions', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255)->unique();
            $table->timestamps();
        });

        // 4. duties (ภารกิจ)
        Schema::create('duties', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255)->unique();
            $table->timestamps();
        });

        // 5. groups (กลุ่มงาน)
        Schema::create('groups', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255)->unique();
            $table->timestamps();
        });

        // 6. works (งาน)
        Schema::create('works', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255)->unique();
            $table->timestamps();
        });

        // 7. departments (หน่วย)
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255)->unique();
            $table->timestamps();
        });

        // 8. employee_statuses (สถานะ)
        Schema::create('employee_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->string('color', 20)->default('gray');
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_statuses');
        Schema::dropIfExists('departments');
        Schema::dropIfExists('works');
        Schema::dropIfExists('groups');
        Schema::dropIfExists('duties');
        Schema::dropIfExists('positions');
        Schema::dropIfExists('employee_types');
        Schema::dropIfExists('prefixes');
    }
};
