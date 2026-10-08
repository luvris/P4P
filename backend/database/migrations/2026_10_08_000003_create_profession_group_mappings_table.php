<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ตารางจับคู่ "ชื่อตำแหน่งในไฟล์เงินเดือน" → กลุ่มวิชาชีพ 9 กลุ่ม ของกรอบวงเงิน P4P
 *
 * เดิมใช้กฎคำสำคัญอย่างเดียวในโค้ด ทำให้บางตำแหน่ง (เช่น ผู้ช่วยทันตแพทย์, วิศวกร)
 * ถูกจัดผิดหรือจัดไม่ได้ ตารางนี้ให้แก้ได้เองผ่านหน้าจอ
 *
 * - position_name = ค่าที่ปรากฏในไฟล์ (payrolls.position_name)
 * - group_code = รหัสกลุ่มใน ProfessionalGroupCatalog (null = ยังไม่กำหนด)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profession_group_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('position_name', 255)->unique();
            $table->string('group_code', 50)->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profession_group_mappings');
    }
};
