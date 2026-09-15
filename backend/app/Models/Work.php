<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Work extends Model
{
    protected $fillable = ['group_id', 'name'];

    /**
     * งาน อยู่ในกลุ่มงาน
     */
    public function group()
    {
        return $this->belongsTo(Group::class);
    }

    /**
     * งาน มีหลาย employees
     */
    public function employees()
    {
        return $this->hasMany(Employee::class);
    }
}
