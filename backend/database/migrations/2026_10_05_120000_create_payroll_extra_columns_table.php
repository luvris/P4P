<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * คอลัมน์เพิ่มเติมที่ผู้ใช้กำหนดเอง
 *
 * ไฟล์เงินเดือนเดิมมีคอลัมน์ตายตัว 39 ช่อง ถ้าหน่วยงานต้องการเก็บข้อมูลเพิ่ม
 * (เช่น ค่าครองชีพเฉพาะหน่วย) ไม่ต้องแก้โค้ดทุกครั้ง — ประกาศชื่อคอลัมน์ที่นี่
 * ระบบจะใส่ลงไฟล์ต้นแบบให้ และเก็บค่าที่อัปโหลดมาไว้ใน payrolls.extra_data
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_extra_columns', function (Blueprint $table) {
            $table->id();
            // ชื่อที่แสดงในไฟล์ต้นแบบและในไฟล์ที่อัปโหลด (ห้ามซ้ำ)
            $table->string('name', 100)->unique();
            // slug ใช้เป็น key ใน extra_data ต้องไม่ซ้ำและเปลี่ยนไม่ได้ง่าย
            $table->string('key', 60)->unique();
            $table->string('description', 255)->nullable();
            // text | number | date — ใช้ตรวจค่าตอนอัปโหลดและจัดรูปแบบตอนแสดง
            $table->string('data_type', 20)->default('text');
            $table->boolean('is_active')->default(true);
            // ลำดับแสดงผลในไฟล์ต้นแบบ (ต่อท้าย 39 คอลัมน์เดิมเสมอ)
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });

        // ค่าคอลัมน์เพิ่มเติมของแต่ละแถวเงินเดือน (เก็บเป็น JSON เพื่อไม่ต้อง
        // สร้างคอลัมน์ใหม่ในตารางทุกครั้งที่เพิ่มคอลัมน์)
        Schema::table('payrolls', function (Blueprint $table) {
            $table->json('extra_data')->nullable()->after('note');
        });
    }

    public function down(): void
    {
        Schema::table('payrolls', function (Blueprint $table) {
            $table->dropColumn('extra_data');
        });

        Schema::dropIfExists('payroll_extra_columns');
    }
};