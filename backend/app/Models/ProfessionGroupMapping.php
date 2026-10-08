<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * จับคู่ชื่อตำแหน่ง (จากไฟล์เงินเดือน) → กลุ่มวิชาชีพของกรอบวงเงิน P4P
 *
 * group_code อ้างรหัสใน App\Support\ProfessionalGroupCatalog
 */
class ProfessionGroupMapping extends Model
{
    protected $fillable = [
        'position_name',
        'group_code',
    ];

    /** mapping ทั้งหมดเป็น [position_name => group_code] */
    public static function codeMap(): array
    {
        return static::query()
            ->whereNotNull('group_code')
            ->pluck('group_code', 'position_name')
            ->all();
    }
}
