<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeType extends Model
{
    protected $fillable = ['name', 'sort_order'];

    public function employees()
    {
        return $this->hasMany(Employee::class);
    }
}
