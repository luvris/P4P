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
            // PID จะเป็น citizen_id ที่มีอยู่แล้ว
            // HID - Hospital ID or HR ID
            $table->string('hid', 50)->nullable()->after('citizen_id')->comment('รหัสโรงพยาบาล/HR ID');
            
            // TTL จะใช้ prefix_id ที่มีอยู่แล้ว
            
            // SEX - เพศ
            $table->enum('sex', ['M', 'F', 'O'])->nullable()->after('last_name')->comment('เพศ: M=ชาย, F=หญิง, O=อื่นๆ');
            
            // BLOOD - กรุ๊ปเลือด
            $table->string('blood_type', 10)->nullable()->after('sex')->comment('กรุ๊ปเลือด');
            
            // BRON - วันเกิด
            $table->date('birth_date')->nullable()->after('blood_type')->comment('วันเกิด');
            
            // TEL, MOBILE - เบอร์โทรศัพท์
            $table->string('tel', 20)->nullable()->after('birth_date')->comment('เบอร์โทรบ้าน');
            $table->string('mobile', 20)->nullable()->after('tel')->comment('เบอร์มือถือ');
            
            // ADDRESS1, ADDRESS2 - ที่อยู่
            $table->text('address1')->nullable()->after('mobile')->comment('ที่อยู่ 1');
            $table->text('address2')->nullable()->after('address1')->comment('ที่อยู่ 2');
            
            // ETTL, ENAME, ELNAME - ข้อมูลผู้ติดต่อฉุกเฉิน
            $table->string('emergency_contact_prefix', 50)->nullable()->after('address2')->comment('คำนำหน้าผู้ติดต่อฉุกเฉิน');
            $table->string('emergency_contact_name', 255)->nullable()->after('emergency_contact_prefix')->comment('ชื่อผู้ติดต่อฉุกเฉิน');
            $table->string('emergency_contact_lname', 255)->nullable()->after('emergency_contact_name')->comment('นามสกุลผู้ติดต่อฉุกเฉิน');
            
            // STATUS จะใช้ status_id ที่มีอยู่แล้ว
            
            // LEAVES_BY - ประเภทการลา
            $table->string('leaves_by', 50)->nullable()->after('emergency_contact_lname')->comment('ประเภทการลา');
            
            // FINGER - ลายนิ้วมือ
            $table->string('finger', 100)->nullable()->after('leaves_by')->comment('รหัสลายนิ้วมือ');
            
            // EMAIL
            $table->string('email', 255)->nullable()->after('finger')->comment('อีเมล');
            
            // TOKENLINE - Line token
            $table->string('line_token', 255)->nullable()->after('email')->comment('Line Token');
            
            // เพิ่ม index
            $table->index('hid');
            $table->index('email');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn([
                'hid',
                'sex',
                'blood_type',
                'birth_date',
                'tel',
                'mobile',
                'address1',
                'address2',
                'emergency_contact_prefix',
                'emergency_contact_name',
                'emergency_contact_lname',
                'leaves_by',
                'finger',
                'email',
                'line_token'
            ]);
        });
    }
};
