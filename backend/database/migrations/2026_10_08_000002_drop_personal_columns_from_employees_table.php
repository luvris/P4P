<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ลบคอลัมน์ส่วนตัวของบุคลากรที่ "ไม่มีในไฟล์เงินเดือนรูปแบบใหม่"
 *
 * ไฟล์ รวมต้นทุน (รูปแบบใหม่) มีคอลัมน์ด้านบุคลากรเพียง:
 *   คำนำหน้า | ชื่อ | นามสกุล | ประเภท | ตำแหน่ง | ตำแหน่งเลขที่
 *   ID CARD | เลขที่บัญชี | เลขที่บัญชี.1 | หมายเหตุ
 *
 * คอลัมน์เดิมที่นำเข้ามาจากระบบเก่า (HID/SEX/BLOOD/BRON/TEL/ADDRESS/ENAME ฯลฯ)
 * ไม่มีในไฟล์และไม่มีโค้ดใดอ่าน จึงลบทิ้ง
 *
 * เพื่อกันข้อมูลที่เคยกรอกไว้หายถาวรโดยไม่มีทางกู้ จะสำเนาไปที่
 * employees_personal_backup ก่อน แล้วค่อยลบคอลัมน์
 *
 * หมายเหตุ SQLite: ต้องลบ index ก่อนลบคอลัมน์ที่ถูก index ไว้ (hid, email)
 */
return new class extends Migration
{
    /** คอลัมน์ส่วนตัวที่จะย้ายไปสำรองและลบออกจาก employees */
    private const PERSONAL_COLUMNS = [
        'hid', 'sex', 'blood_type', 'birth_date', 'tel', 'mobile',
        'address1', 'address2',
        'english_prefix', 'english_first_name', 'english_last_name',
        'leaves_by', 'finger', 'email', 'line_token',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('employees_personal_backup')) {
            Schema::create('employees_personal_backup', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('employee_id')->index();
                $table->string('citizen_id', 20)->nullable();

                $table->string('hid', 50)->nullable();
                $table->string('sex', 10)->nullable();
                $table->string('blood_type', 10)->nullable();
                $table->date('birth_date')->nullable();
                $table->string('tel', 20)->nullable();
                $table->string('mobile', 20)->nullable();
                $table->text('address1')->nullable();
                $table->text('address2')->nullable();
                $table->string('english_prefix', 50)->nullable();
                $table->string('english_first_name', 255)->nullable();
                $table->string('english_last_name', 255)->nullable();
                $table->string('leaves_by', 50)->nullable();
                $table->string('finger', 100)->nullable();
                $table->string('email', 255)->nullable();
                $table->string('line_token', 255)->nullable();

                $table->timestamps();
            });
        }

        // สำเนาข้อมูลเดิมก่อนลบ (เฉพาะคอลัมน์ที่มีอยู่จริงในตาราง)
        $available = array_values(array_filter(
            self::PERSONAL_COLUMNS,
            fn (string $column) => Schema::hasColumn('employees', $column),
        ));

        if ($available !== []) {
            $rows = DB::table('employees')
                ->get(array_merge(['id', 'citizen_id'], $available));

            $payload = [];
            $now = now();

            foreach ($rows as $row) {
                $record = [
                    'employee_id' => $row->id,
                    'citizen_id'  => $row->citizen_id,
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ];

                foreach ($available as $column) {
                    $record[$column] = $row->{$column};
                }

                $payload[] = $record;
            }

            if ($payload !== []) {
                DB::table('employees_personal_backup')->insert($payload);
            }

            Schema::table('employees', function (Blueprint $table) use ($available) {
                // ลบ index ก่อนลบคอลัมน์ (ถูกลบซ้ำ/ไม่มีจริงจะถูกข้ามในลูปด้านล่าง)
                foreach (['hid' => 'employees_hid_index', 'email' => 'employees_email_index'] as $column => $index) {
                    if (in_array($column, $available, true) && Schema::hasIndex('employees', $index)) {
                        $table->dropIndex($index);
                    }
                }
            });

            Schema::table('employees', function (Blueprint $table) use ($available) {
                $table->dropColumn($available);
            });
        }
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            if (! Schema::hasColumn('employees', 'hid')) {
                $table->string('hid', 50)->nullable()->after('citizen_id');
            }
            if (! Schema::hasColumn('employees', 'sex')) {
                $table->string('sex', 10)->nullable()->after('last_name');
            }
            if (! Schema::hasColumn('employees', 'blood_type')) {
                $table->string('blood_type', 10)->nullable()->after('sex');
            }
            if (! Schema::hasColumn('employees', 'birth_date')) {
                $table->date('birth_date')->nullable()->after('blood_type');
            }
            if (! Schema::hasColumn('employees', 'tel')) {
                $table->string('tel', 20)->nullable()->after('birth_date');
            }
            if (! Schema::hasColumn('employees', 'mobile')) {
                $table->string('mobile', 20)->nullable()->after('tel');
            }
            if (! Schema::hasColumn('employees', 'address1')) {
                $table->text('address1')->nullable()->after('mobile');
            }
            if (! Schema::hasColumn('employees', 'address2')) {
                $table->text('address2')->nullable()->after('address1');
            }
            if (! Schema::hasColumn('employees', 'english_prefix')) {
                $table->string('english_prefix', 50)->nullable()->after('address2');
            }
            if (! Schema::hasColumn('employees', 'english_first_name')) {
                $table->string('english_first_name', 255)->nullable()->after('english_prefix');
            }
            if (! Schema::hasColumn('employees', 'english_last_name')) {
                $table->string('english_last_name', 255)->nullable()->after('english_first_name');
            }
            if (! Schema::hasColumn('employees', 'leaves_by')) {
                $table->string('leaves_by', 50)->nullable()->after('english_last_name');
            }
            if (! Schema::hasColumn('employees', 'finger')) {
                $table->string('finger', 100)->nullable()->after('leaves_by');
            }
            if (! Schema::hasColumn('employees', 'email')) {
                $table->string('email', 255)->nullable()->after('finger');
            }
            if (! Schema::hasColumn('employees', 'line_token')) {
                $table->string('line_token', 255)->nullable()->after('email');
            }
        });

        // คืนค่าที่สำรองไว้กลับไปยังคอลัมน์ (ถ้ามี)
        if (Schema::hasTable('employees_personal_backup')) {
            foreach (DB::table('employees_personal_backup')->get() as $row) {
                $update = [];

                foreach (self::PERSONAL_COLUMNS as $column) {
                    if (Schema::hasColumn('employees', $column)) {
                        $update[$column] = $row->{$column};
                    }
                }

                if ($update !== []) {
                    DB::table('employees')->where('id', $row->employee_id)->update($update);
                }
            }

            Schema::dropIfExists('employees_personal_backup');
        }
    }
};
