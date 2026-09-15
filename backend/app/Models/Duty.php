<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Duty extends Model
{
    protected $fillable = ['name'];

    /**
     * ภารกิจ มีหลายกลุ่มงาน
     */
    public function groups()
    {
        return $this->hasMany(Group::class);
    }

    /**
     * ภารกิจ มีหลาย employees
     */
    public function employees()
    {
        return $this->hasMany(Employee::class);
    }
}
