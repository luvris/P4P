<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Prefix extends Model
{
    protected $fillable = ['name', 'short_name', 'sort_order'];

    public function employees()
    {
        return $this->hasMany(Employee::class);
    }
}
