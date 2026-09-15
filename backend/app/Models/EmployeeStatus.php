<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeStatus extends Model
{
    protected $fillable = ['name', 'color', 'sort_order'];

    public function employees()
    {
        return $this->hasMany(Employee::class);
    }
}
