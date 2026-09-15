<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Group extends Model
{
    protected $fillable = ['duty_id', 'name'];

    /**
     * กลุ่มงาน อยู่ในภารกิจ
     */
    public function duty()
    {
        return $this->belongsTo(Duty::class);
    }

    /**
     * กลุ่มงาน มีหลายงาน
     */
    public function works()
    {
        return $this->hasMany(Work::class);
    }

    /**
     * กลุ่มงาน มีหลาย employees
     */
    public function employees()
    {
        return $this->hasMany(Employee::class);
    }
}
