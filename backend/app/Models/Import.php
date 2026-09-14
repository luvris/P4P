<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Import extends Model
{
    use HasFactory;

    /**
     * ชื่อตาราง
     */
    protected $table = 'imports';

    /**
     * ฟิลด์ที่สามารถ mass assign ได้
     */
    protected $fillable = [
        'file_name',
        'file_path',
        'file_type',
        'uploaded_by',
        'total_rows',
        'success_rows',
        'error_rows',
        'duplicate_rows',
        'status',
        'error_message',
    ];

    /**
     * Type casting
     */
    protected $casts = [
        'total_rows' => 'integer',
        'success_rows' => 'integer',
        'error_rows' => 'integer',
        'duplicate_rows' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * ค่า default ของ status
     */
    protected $attributes = [
        'status' => 'pending',
        'total_rows' => 0,
        'success_rows' => 0,
        'error_rows' => 0,
        'duplicate_rows' => 0,
    ];

    /*
    Relationships
    */

    /**
     * ผู้ใชที่อัปโหลดไฟล์
     */
    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * รายการ payroll ทั้งหมดที่มาจากการ import นี้
     */
    public function payrolls()
    {
        return $this->hasMany(Payroll::class);
    }

    /*
    Accessors (สำหรับแสดงผล)
    */

    /**
     * แสดงสถานะเป็นภาษาไทย
     */
    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            'pending' => 'รอดำเนินการ',
            'processing' => 'กำลังประมวลผล',
            'completed' => 'เสร็จสิ้น',
            'failed' => 'ล้มเหลว',
            default => 'ไม่ทราบสถานะ',
        };
    }

    /**
     * เปอร์เซ็นต์ความสำเร็จ
     */
    public function getSuccessRateAttribute(): float
    {
        if ($this->total_rows === 0) {
            return 0;
        }

        return round(($this->success_rows / $this->total_rows) * 100, 2);
    }
}
