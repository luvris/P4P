<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * คอลัมน์เพิ่มเติมของไฟล์เงินเดือนที่ผู้ใช้ประกาศเอง
 *
 * เวลานำเข้า ค่าจะถูกเก็บลง payrolls.extra_data ในรูปแบบ
 * { "<key>": "<ค่า>" } คอลัมน์ที่ปิดใช้งาน (is_active = false) จะไม่ถูกอ่าน
 * จากไฟล์ใหม่อีก แต่ข้อมูลที่เก็บไว้แล้วไม่หาย
 *
 * @property int $id
 * @property string $name
 * @property string $key
 * @property string|null $description
 * @property string $data_type
 * @property bool $is_active
 * @property int $sort_order
 */
class PayrollExtraColumn extends Model
{
    use HasFactory;

    /** ชนิดข้อมูลที่ยอมรับ */
    public const TYPES = ['text', 'number', 'date'];

    protected $fillable = [
        'name', 'key', 'description', 'data_type', 'is_active', 'sort_order', 'created_by',
    ];

    protected $casts = [
        'is_active'   => 'boolean',
        'sort_order'  => 'integer',
    ];

    /**
     * สร้าง key จากชื่อคอลัมน์ภาษาไทยให้เป็น slug อังกฤษที่ใช้เป็น key ได้
     *
     * ชื่อไทยแปลงเป็น slug ไม่ได้ จึงต้องมีค่าสำรอง — ถ้าชื่อไม่มีอักษรละตินเลย
     * ใช้ลำดับเลขกำกับ เช่น extra_1, extra_2 (ผู้ใช้แก้ชื่อทีหลังได้)
     */
    public static function makeKey(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name);

        if ($base === '') {
            $base = 'extra_' . substr(md5($name . microtime(true)), 0, 6);
        }

        $base = Str::limit($base, 50, '');
        $key = $base;
        $suffix = 2;

        // ชน key ซ้ำ — key ต้องไม่ซ้ำเพราะใช้เป็นชื่อฟิลด์ใน extra_data
        while (static::where('key', $key)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists()) {
            $key = $base . '_' . $suffix++;
        }

        return $key;
    }

    /**
     * คอลัมน์ที่ใช้งานอยู่ เรียงตามลำดับที่ตั้งไว้
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, self>
     */
    public static function active()
    {
        return static::where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }
}