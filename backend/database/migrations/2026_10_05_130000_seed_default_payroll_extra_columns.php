<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ใส่คอลัมน์ที่หน่วยงานใช้ทุกงวด มาในไฟล์ต้นแบบตั้งแต่แรก
 *
 * ภารกิจ / กลุ่มงาน / งาน อยู่ในไฟล์เงินเดือนจริงมานานแล้ว แต่ระบบไม่มีที่เก็บ
 * ค่าจึงหายไปทุกครั้งที่อัปโหลด ประกาศเป็นคอลัมน์เพิ่มเติมตั้งต้นแทนการแก้โค้ด
 * ผู้ดูแลระบบยังเปลี่ยนชื่อ ปิดใช้งาน หรือเพิ่มคอลัมน์อื่นได้ตามปกติ
 *
 * รันซ้ำได้โดยไม่พัง — ถ้ามีคอลัมน์ชื่อนี้อยู่แล้วจะข้าม
 */
return new class extends Migration
{
    /** @var array<int, array<string, string>> */
    protected array $defaults = [
        ['name' => 'ภารกิจ', 'key' => 'duty', 'description' => 'ภารกิจหลักของบุคลากร'],
        ['name' => 'กลุ่มงาน', 'key' => 'work_group', 'description' => 'กลุ่มงานที่สังกัด'],
        ['name' => 'งาน', 'key' => 'work', 'description' => 'งานที่รับผิดชอบ'],
    ];

    public function up(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('payroll_extra_columns')) {
            return;
        }

        $sortOrder = (int) DB::table('payroll_extra_columns')->max('sort_order');

        foreach ($this->defaults as $default) {
            $alreadyThere = DB::table('payroll_extra_columns')
                ->where('name', $default['name'])
                ->orWhere('key', $default['key'])
                ->exists();

            if ($alreadyThere) {
                continue;
            }

            DB::table('payroll_extra_columns')->insert($default + [
                'data_type'  => 'text',
                'is_active'  => true,
                'sort_order' => ++$sortOrder,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('payroll_extra_columns')) {
            return;
        }

        DB::table('payroll_extra_columns')
            ->whereIn('key', array_column($this->defaults, 'key'))
            ->delete();
    }
};