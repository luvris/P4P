<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeStatus extends Model
{
    /**
     * สถานะของคนที่ยังปฏิบัติงานอยู่
     *
     * ใช้เป็นค่าเริ่มต้นให้พนักงานที่สร้างใหม่จากการนำเข้าไฟล์เงินเดือน
     * และใช้เป็นเงื่อนไขของฐานเงินสำรอง (นับเฉพาะคนที่ยังปฏิบัติงานอยู่)
     * จึงเก็บชื่อไว้ที่เดียว ไม่ให้สองที่หลุดจากกัน
     */
    public const ACTIVE_NAME = 'ปฏิบัติงานอยู่';

    protected $fillable = ['name', 'color', 'sort_order'];

    /**
     * id ของสถานะ "ปฏิบัติงานอยู่" (null ถ้ายังไม่มีในระบบ)
     */
    public static function activeId(): ?int
    {
        $id = static::where('name', self::ACTIVE_NAME)->value('id');

        return $id === null ? null : (int) $id;
    }

    public function employees()
    {
        return $this->hasMany(Employee::class);
    }
}
